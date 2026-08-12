import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useRecConfig } from './recConfig';

const FLUSH_MS = 150;
const TIMESLICE_MS = 1000;

function wait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function makeUuid() {
    return globalThis.crypto?.randomUUID?.()
        || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function isAppleMobile() {
    if (typeof navigator === 'undefined') return false;
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function pickMimeType() {
    if (typeof MediaRecorder === 'undefined') return '';
    const candidates = isAppleMobile()
        ? [
            'video/mp4',
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp8',
            'video/webm',
        ]
        : [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp8',
            'video/webm',
            'video/mp4',
        ];
    return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

/**
 * V1-style rolling buffer: MediaRecorder in memory only.
 * No IndexedDB, no continuous upload — that path broke iOS after REC V2.
 */
export function useRecCapture(options = {}) {
    const config = useRecConfig(options.config);
    const bufferSeconds = Math.max(10, Number(config.buffer_seconds) || 30);
    const minClipSeconds = Math.max(5, Math.min(bufferSeconds - 5, 25));
    const segmentMs = bufferSeconds * 1000;

    const isRecording = ref(false);
    const isSupported = ref(
        typeof window !== 'undefined'
        && !!navigator.mediaDevices?.getUserMedia
        && typeof MediaRecorder !== 'undefined',
    );
    const error = ref(null);
    const previewEl = ref(null);
    const hasAudio = ref(true);
    const availableMs = ref(0);

    let mediaStream = null;
    let mediaRecorder = null;
    let mimeType = '';
    const chunks = [];
    let segmentStartedAt = 0;
    let recordingStartedAt = 0;
    let previousSegment = null;
    let segmentTimer = null;
    let availableTimer = null;
    let shouldKeepRecording = false;
    let operationChain = Promise.resolve();
    let sequence = 0;

    function blobType() {
        return (mimeType || chunks[0]?.type || 'video/webm').split(';')[0];
    }

    function currentDurationMs() {
        if (!segmentStartedAt) return 0;
        return Math.max(0, Date.now() - segmentStartedAt);
    }

    function tickAvailableMs() {
        if (!recordingStartedAt) {
            availableMs.value = 0;
            return 0;
        }
        // Prefer real recorded footage length (current + previous segment).
        const fromSegments = currentDurationMs()
            + (previousSegment?.durationMs || 0);
        const fromClock = Math.max(0, Date.now() - recordingStartedAt);
        const ms = Math.min(bufferSeconds * 1000, Math.max(fromSegments, fromClock));
        availableMs.value = ms;
        return ms;
    }

    function startAvailableTimer() {
        stopAvailableTimer();
        tickAvailableMs();
        availableTimer = setInterval(tickAvailableMs, 500);
    }

    function stopAvailableTimer() {
        clearInterval(availableTimer);
        availableTimer = null;
    }

    function attachPreview() {
        if (!previewEl.value || !mediaStream) return;
        previewEl.value.srcObject = mediaStream;
        previewEl.value.muted = true;
        previewEl.value.playsInline = true;
        previewEl.value.setAttribute('playsinline', 'true');
        previewEl.value.setAttribute('webkit-playsinline', 'true');
        previewEl.value.play().catch(() => {});
    }

    function runExclusive(task) {
        const next = operationChain.then(task, task);
        operationChain = next.catch(() => {});
        return next;
    }

    function isFrontCameraTrack(track) {
        if (!track) return false;
        const label = (track.label || '').toLowerCase();
        const facing = track.getSettings?.()?.facingMode;
        if (facing === 'environment') return false;
        if (facing === 'user') return true;
        return label.includes('front')
            || label.includes('frontal')
            || label.includes('facetime')
            || /\buser\b/.test(label);
    }

    async function getMediaStream() {
        const attempts = [
            { audio: !isAppleMobile(), video: { facingMode: { exact: 'environment' } } },
            { audio: false, video: { facingMode: { exact: 'environment' } } },
            { audio: false, video: { facingMode: 'environment' } },
            { audio: false, video: { facingMode: { ideal: 'environment' } } },
            {
                audio: true,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    frameRate: { ideal: 24, max: 30 },
                },
            },
        ];

        let lastError = null;
        for (const constraints of attempts) {
            try {
                const stream = await navigator.mediaDevices.getUserMedia(constraints);
                if (isFrontCameraTrack(stream.getVideoTracks()[0])) {
                    stream.getTracks().forEach((track) => track.stop());
                    continue;
                }
                hasAudio.value = constraints.audio === true;
                return stream;
            } catch (err) {
                lastError = err;
            }
        }
        throw lastError || new Error('getUserMedia failed');
    }

    function recorderOptions() {
        const options = {};
        if (mimeType) options.mimeType = mimeType;
        if (!isAppleMobile()) {
            options.videoBitsPerSecond = 1_200_000;
            if (hasAudio.value) options.audioBitsPerSecond = 96_000;
        }
        return options;
    }

    function startRecorder() {
        if (!mediaStream || !shouldKeepRecording) return;

        chunks.length = 0;
        segmentStartedAt = Date.now();
        mediaRecorder = new MediaRecorder(mediaStream, recorderOptions());
        mediaRecorder.ondataavailable = (event) => {
            if (event.data?.size) chunks.push(event.data);
        };
        mediaRecorder.onerror = () => {
            error.value = 'Erro na gravação.';
        };
        // V1 used timeslice successfully; keep it for all devices including iOS.
        mediaRecorder.start(TIMESLICE_MS);
    }

    function finalizeCurrentSegment() {
        return new Promise((resolve) => {
            if (!mediaRecorder || mediaRecorder.state === 'inactive') {
                const blob = chunks.length
                    ? new Blob(chunks.slice(), { type: blobType() })
                    : null;
                resolve({ blob, durationMs: currentDurationMs(), startedAt: segmentStartedAt });
                return;
            }

            const recorder = mediaRecorder;
            const startedAt = segmentStartedAt;
            let settled = false;

            const finish = async () => {
                if (settled) return;
                settled = true;
                await wait(FLUSH_MS);
                const durationMs = Math.max(0, Date.now() - startedAt);
                const blob = chunks.length
                    ? new Blob(chunks.slice(), { type: blobType() })
                    : null;
                chunks.length = 0;
                mediaRecorder = null;
                resolve({ blob, durationMs, startedAt });
            };

            recorder.onstop = () => finish();
            try {
                recorder.requestData();
            } catch {
                // ignore
            }
            try {
                recorder.stop();
            } catch {
                finish();
            }
        });
    }

    async function rotateSegment() {
        return runExclusive(async () => {
            if (!shouldKeepRecording || !mediaRecorder || mediaRecorder.state !== 'recording') {
                return;
            }

            const { blob, durationMs, startedAt } = await finalizeCurrentSegment();
            if (blob?.size && durationMs >= minClipSeconds * 1000) {
                previousSegment = {
                    blob,
                    durationMs,
                    startedAt,
                    endedAt: Date.now(),
                };
            }

            if (shouldKeepRecording) {
                startRecorder();
            }
            tickAvailableMs();
        });
    }

    function startSegmentTimer() {
        stopSegmentTimer();
        segmentTimer = setInterval(() => {
            rotateSegment().catch(() => {});
        }, segmentMs);
    }

    function stopSegmentTimer() {
        clearInterval(segmentTimer);
        segmentTimer = null;
    }

    async function start() {
        error.value = null;
        if (!isSupported.value) {
            error.value = 'Gravação não suportada neste navegador.';
            return false;
        }
        if (isRecording.value) return true;

        try {
            mediaStream = await getMediaStream();
            mimeType = pickMimeType();
            previousSegment = null;
            shouldKeepRecording = true;
            operationChain = Promise.resolve();
            sequence = 0;
            recordingStartedAt = Date.now();

            attachPreview();
            startRecorder();
            startSegmentTimer();
            startAvailableTimer();
            isRecording.value = true;

            await nextTick();
            attachPreview();
            return true;
        } catch (err) {
            const name = err?.name || '';
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                error.value = 'Permissão da câmera negada.';
            } else if (name === 'NotFoundError') {
                error.value = 'Nenhuma câmera encontrada.';
            } else if (name === 'NotReadableError') {
                error.value = 'A câmera está em uso por outro aplicativo.';
            } else {
                error.value = 'Não foi possível acessar a câmera.';
            }
            stop();
            return false;
        }
    }

    /** Compatibility no-op: V1 starts encoding inside start(). */
    async function startEncoding() {
        return isRecording.value && !!mediaRecorder;
    }

    /**
     * Capture ending at the click (V1 snapshot semantics).
     */
    async function snapshot() {
        return runExclusive(async () => {
            stopSegmentTimer();
            try {
                const { blob, durationMs, startedAt } = await finalizeCurrentSegment();
                const endedAt = Date.now();

                if (shouldKeepRecording) {
                    startRecorder();
                    startSegmentTimer();
                }

                const minMs = minClipSeconds * 1000;
                const currentOk = !!(blob && blob.size > 0);

                if (currentOk && durationMs >= minMs) {
                    return {
                        parts: [{
                            blob,
                            startedAt: startedAt || (endedAt - durationMs),
                            endedAt,
                            durationMs,
                        }],
                        durationSeconds: Math.round(durationMs / 1000),
                    };
                }

                if (previousSegment?.blob?.size) {
                    const parts = [{
                        blob: previousSegment.blob,
                        startedAt: previousSegment.startedAt || (previousSegment.endedAt - previousSegment.durationMs),
                        endedAt: previousSegment.endedAt,
                        durationMs: previousSegment.durationMs,
                    }];
                    if (currentOk) {
                        parts.push({
                            blob,
                            startedAt: startedAt || (endedAt - durationMs),
                            endedAt,
                            durationMs,
                        });
                    }
                    return {
                        parts,
                        durationSeconds: bufferSeconds,
                    };
                }

                if (currentOk) {
                    return {
                        parts: [{
                            blob,
                            startedAt: startedAt || (endedAt - durationMs),
                            endedAt,
                            durationMs,
                        }],
                        durationSeconds: Math.round(durationMs / 1000),
                    };
                }

                return null;
            } catch {
                if (previousSegment?.blob?.size) {
                    return {
                        parts: [{
                            blob: previousSegment.blob,
                            startedAt: previousSegment.startedAt
                                || (previousSegment.endedAt - previousSegment.durationMs),
                            endedAt: previousSegment.endedAt,
                            durationMs: previousSegment.durationMs,
                        }],
                        durationSeconds: Math.round(previousSegment.durationMs / 1000) || bufferSeconds,
                    };
                }
                return null;
            }
        });
    }

    function hasBuffer() {
        const minMs = minClipSeconds * 1000;
        if (currentDurationMs() >= minMs) return true;
        return !!(previousSegment?.blob?.size && previousSegment.durationMs >= minMs);
    }

    async function listLocalSegmentsInWindow() {
        // V1 keeps only rolling memory; SAVE path uses snapshot() instead.
        return [];
    }

    function nextSequence() {
        sequence += 1;
        return sequence;
    }

    function stop() {
        shouldKeepRecording = false;
        stopSegmentTimer();
        stopAvailableTimer();
        operationChain = Promise.resolve();

        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.ondataavailable = null;
            mediaRecorder.onerror = null;
            mediaRecorder.onstop = null;
            try {
                mediaRecorder.stop();
            } catch {
                // ignore
            }
        }

        mediaRecorder = null;
        chunks.length = 0;
        previousSegment = null;
        segmentStartedAt = 0;
        recordingStartedAt = 0;
        availableMs.value = 0;

        mediaStream?.getTracks?.().forEach((track) => track.stop());
        mediaStream = null;
        if (previewEl.value) previewEl.value.srcObject = null;
        isRecording.value = false;
    }

    function getAvailableMsSync() {
        return tickAvailableMs();
    }

    async function getAvailableMs() {
        return getAvailableMsSync();
    }

    onBeforeUnmount(() => stop());

    return {
        start,
        startEncoding,
        stop,
        snapshot,
        hasBuffer,
        listLocalSegmentsInWindow,
        nextSequence,
        makeUuid,
        isRecording,
        isSupported,
        error,
        previewEl,
        hasAudio,
        availableMs,
        attachPreview,
        getAvailableMs,
        getAvailableMsSync,
        bufferSeconds,
        minClipSeconds,
    };
}
