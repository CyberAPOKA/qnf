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

/**
 * V1-style buffer: one tap → camera + MediaRecorder. Upload only on SAVE (via session ack).
 */
export function useRecCapture(options = {}) {
    const config = useRecConfig(options.config);
    const apple = isAppleMobile();
    const bufferSeconds = Math.max(10, Number(config.buffer_seconds) || 30);
    const minClipSeconds = Math.max(5, Math.min(bufferSeconds - 5, 25));
    const segmentMs = bufferSeconds * 1000;

    function pickMimeType() {
        if (typeof MediaRecorder === 'undefined') return '';
        // iOS: let Safari pick the container — forced mime types hang or freeze.
        if (apple) return '';
        const candidates = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp8',
            'video/webm',
            'video/mp4',
        ];
        return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
    }

    const isRecording = ref(false);
    const encodingReady = ref(false);
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
        const ms = Math.min(bufferSeconds * 1000, Math.max(0, Date.now() - recordingStartedAt));
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

    function attachPreview(stream = mediaStream) {
        if (!previewEl.value || !stream) return false;
        previewEl.value.srcObject = stream;
        previewEl.value.muted = true;
        previewEl.value.playsInline = true;
        previewEl.value.setAttribute('playsinline', 'true');
        previewEl.value.setAttribute('webkit-playsinline', 'true');
        previewEl.value.play().catch(() => {});
        return true;
    }

    function runExclusive(task) {
        const next = operationChain.then(task, task);
        operationChain = next.catch(() => {});
        return next;
    }

    async function getMediaStream() {
        if (apple) {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                },
            });
            hasAudio.value = false;
            return stream;
        }

        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
                aspectRatio: { ideal: 16 / 9 },
                frameRate: { ideal: 24, max: 30 },
            },
        });
        hasAudio.value = true;
        return stream;
    }

    function recorderOptions() {
        const opts = {};
        if (mimeType) opts.mimeType = mimeType;
        if (!apple) {
            opts.videoBitsPerSecond = 1_200_000;
            opts.audioBitsPerSecond = 96_000;
        }
        return opts;
    }

    function buildBlobFromChunks() {
        if (!chunks.length) return null;
        return new Blob(chunks.slice(), { type: blobType() });
    }

    function startRecorder() {
        if (!mediaStream || !shouldKeepRecording) return;

        chunks.length = 0;
        segmentStartedAt = Date.now();

        // iOS Safari freezes on timeslice — record continuously until SAVE.
        try {
            mediaRecorder = apple
                ? new MediaRecorder(mediaStream)
                : new MediaRecorder(mediaStream, recorderOptions());
        } catch {
            mediaRecorder = new MediaRecorder(mediaStream);
        }

        mediaRecorder.ondataavailable = (event) => {
            if (event.data?.size) chunks.push(event.data);
        };
        mediaRecorder.onerror = () => {
            error.value = 'Erro na gravação.';
        };

        if (apple) mediaRecorder.start();
        else mediaRecorder.start(TIMESLICE_MS);
    }

    function finalizeCurrentSegment() {
        return new Promise((resolve) => {
            if (!mediaRecorder || mediaRecorder.state === 'inactive') {
                resolve({
                    blob: buildBlobFromChunks(),
                    durationMs: currentDurationMs(),
                    startedAt: segmentStartedAt,
                });
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
                const blob = buildBlobFromChunks();
                chunks.length = 0;
                mediaRecorder = null;
                resolve({ blob, durationMs, startedAt });
            };

            recorder.onstop = () => finish();
            if (!apple) {
                try {
                    recorder.requestData();
                } catch {
                    // ignore
                }
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
        // iOS: never stop/restart MediaRecorder on a timer — freezes Safari.
        if (apple) return;
        segmentTimer = setInterval(() => {
            rotateSegment().catch(() => {});
        }, segmentMs);
    }

    function stopSegmentTimer() {
        clearInterval(segmentTimer);
        segmentTimer = null;
    }

    /** One tap: camera + buffer (V1). */
    async function start() {
        error.value = null;
        encodingReady.value = false;

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

            // V1 order: preview + recorder BEFORE flipping isRecording.
            attachPreview(mediaStream);
            startRecorder();
            startSegmentTimer();
            startAvailableTimer();

            isRecording.value = true;
            encodingReady.value = true;

            await nextTick();
            attachPreview(mediaStream);

            return true;
        } catch (err) {
            error.value = err?.name === 'NotAllowedError'
                ? 'Permissão da câmera negada.'
                : 'Não foi possível acessar a câmera.';
            stop();
            return false;
        }
    }

    async function startEncoding() {
        return isRecording.value;
    }

    async function startWithEncoding() {
        return start();
    }

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
                        startedAt: previousSegment.startedAt
                            || (previousSegment.endedAt - previousSegment.durationMs),
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
                    return { parts, durationSeconds: bufferSeconds };
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
        if (recordingStartedAt && Date.now() - recordingStartedAt >= minMs) {
            return true;
        }
        if (currentDurationMs() >= minMs) return true;
        return !!(previousSegment?.blob?.size && previousSegment.durationMs >= minMs);
    }

    async function listLocalSegmentsInWindow() {
        return [];
    }

    function nextSequence() {
        sequence += 1;
        return sequence;
    }

    function stop() {
        shouldKeepRecording = false;
        encodingReady.value = false;
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
        startWithEncoding,
        startEncoding,
        stop,
        snapshot,
        hasBuffer,
        listLocalSegmentsInWindow,
        nextSequence,
        makeUuid,
        isRecording,
        encodingReady,
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
