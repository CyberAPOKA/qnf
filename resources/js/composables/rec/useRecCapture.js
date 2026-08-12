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
    if (isAppleMobile()) {
        // Empty = browser default; forcing mp4/webm is flaky on iOS.
        return MediaRecorder.isTypeSupported('video/mp4') ? 'video/mp4' : '';
    }
    return [
        'video/webm;codecs=vp8,opus',
        'video/webm;codecs=vp8',
        'video/webm',
        'video/mp4',
    ].find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

/**
 * Preview-first capture. MediaRecorder starts only via startEncoding().
 * On iOS, records a canvas stream (not the live camera track) to avoid Safari freezes.
 */
export function useRecCapture(options = {}) {
    const config = useRecConfig(options.config);
    const apple = isAppleMobile();
    const bufferSeconds = Math.max(10, Number(config.buffer_seconds) || 30);
    const minClipSeconds = Math.max(5, Math.min(bufferSeconds - 5, 25));
    const segmentMs = bufferSeconds * 1000;

    const isRecording = ref(false);
    const encodingReady = ref(false);
    const isSupported = ref(
        typeof window !== 'undefined'
        && !!navigator.mediaDevices?.getUserMedia
        && typeof MediaRecorder !== 'undefined',
    );
    const error = ref(null);
    const previewEl = ref(null);
    const hasAudio = ref(!apple);
    const availableMs = ref(0);

    let mediaStream = null;
    let recordStream = null;
    let mediaRecorder = null;
    let mimeType = '';
    const chunks = [];
    let segmentStartedAt = 0;
    let recordingStartedAt = 0;
    let previousSegment = null;
    let segmentTimer = null;
    let availableTimer = null;
    let freezeWatchdog = null;
    let canvasPump = null;
    let recordCanvas = null;
    let shouldKeepRecording = false;
    let encodingStarted = false;
    let operationChain = Promise.resolve();
    let sequence = 0;

    function blobType() {
        return (mimeType || mediaRecorder?.mimeType || chunks[0]?.type || 'video/mp4').split(';')[0];
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
        const fromSegments = currentDurationMs() + (previousSegment?.durationMs || 0);
        const fromClock = Math.max(0, Date.now() - recordingStartedAt);
        const ms = Math.min(bufferSeconds * 1000, Math.max(fromSegments, fromClock));
        availableMs.value = ms;
        return ms;
    }

    function startAvailableTimer() {
        stopAvailableTimer();
        tickAvailableMs();
        availableTimer = setInterval(tickAvailableMs, 250);
    }

    function stopAvailableTimer() {
        clearInterval(availableTimer);
        availableTimer = null;
    }

    function attachPreview() {
        if (!previewEl.value || !mediaStream) return false;
        previewEl.value.srcObject = mediaStream;
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
            { audio: false, video: { facingMode: { exact: 'environment' } } },
            { audio: false, video: { facingMode: 'environment' } },
            { audio: false, video: { facingMode: { ideal: 'environment' } } },
            ...(apple ? [] : [{
                audio: true,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    frameRate: { ideal: 24, max: 30 },
                },
            }]),
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

    function stopCanvasPump() {
        if (canvasPump) {
            cancelAnimationFrame(canvasPump);
            canvasPump = null;
        }
    }

    function releaseRecordStream() {
        stopCanvasPump();
        if (recordStream && recordStream !== mediaStream) {
            recordStream.getTracks().forEach((track) => {
                try {
                    track.stop();
                } catch {
                    // ignore
                }
            });
        }
        recordStream = null;
    }

    function buildAppleRecordStream() {
        const video = previewEl.value;
        if (!video || typeof HTMLCanvasElement === 'undefined') return null;
        if (!HTMLCanvasElement.prototype.captureStream) return null;

        recordCanvas = recordCanvas || document.createElement('canvas');
        const width = Math.min(1280, video.videoWidth || 640);
        const height = Math.min(720, video.videoHeight || 360);
        recordCanvas.width = width;
        recordCanvas.height = height;
        const ctx = recordCanvas.getContext('2d', { alpha: false });
        if (!ctx) return null;

        stopCanvasPump();
        const fps = 12;
        let lastDraw = 0;
        const draw = (now) => {
            if (!shouldKeepRecording) return;
            if (now - lastDraw >= 1000 / fps) {
                lastDraw = now;
                try {
                    ctx.drawImage(video, 0, 0, width, height);
                } catch {
                    // ignore transient draw errors
                }
            }
            canvasPump = requestAnimationFrame(draw);
        };
        canvasPump = requestAnimationFrame(draw);
        return recordCanvas.captureStream(fps);
    }

    function ensureRecordStream() {
        if (recordStream) return recordStream;
        if (apple) {
            recordStream = buildAppleRecordStream();
            // Last resort: clone camera track (still better than sharing with <video>).
            if (!recordStream && mediaStream) {
                const clones = mediaStream.getVideoTracks().map((track) => track.clone());
                if (clones.length) recordStream = new MediaStream(clones);
            }
        } else {
            recordStream = mediaStream;
        }
        return recordStream;
    }

    function startRecorder() {
        if (!mediaStream || !shouldKeepRecording) return;

        const target = ensureRecordStream();
        if (!target) throw new Error('Stream de gravação indisponível.');

        chunks.length = 0;
        segmentStartedAt = Date.now();
        mimeType = pickMimeType();

        const attempts = [
            () => new MediaRecorder(target),
            () => (mimeType ? new MediaRecorder(target, { mimeType }) : null),
        ];

        let created = null;
        let lastError = null;
        for (const attempt of attempts) {
            try {
                created = attempt();
                if (created) break;
            } catch (err) {
                lastError = err;
            }
        }
        if (!created) throw lastError || new Error('MediaRecorder failed');

        mediaRecorder = created;
        mediaRecorder.ondataavailable = (event) => {
            if (event.data?.size) chunks.push(event.data);
        };
        mediaRecorder.onerror = () => {
            error.value = 'Erro na gravação.';
        };

        // timeslice freezes many iOS Safari builds — never use it there.
        if (apple) mediaRecorder.start();
        else mediaRecorder.start(TIMESLICE_MS);
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
            if (!shouldKeepRecording || !encodingStarted || !mediaRecorder || mediaRecorder.state !== 'recording') {
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

            if (shouldKeepRecording && encodingStarted) {
                await wait(apple ? 200 : 50);
                startRecorder();
            }
            tickAvailableMs();
        });
    }

    function startSegmentTimer() {
        stopSegmentTimer();
        // iOS: never rotate on a timer — stop/start MediaRecorder is what freezes Safari.
        // Snapshot on SAVE finalizes the single continuous recording.
        if (apple) return;
        segmentTimer = setInterval(() => {
            rotateSegment().catch(() => {});
        }, segmentMs);
    }

    function stopSegmentTimer() {
        clearInterval(segmentTimer);
        segmentTimer = null;
    }

    function startFreezeWatchdog() {
        stopFreezeWatchdog();
        let lastMs = availableMs.value;
        let stalled = 0;
        freezeWatchdog = setInterval(() => {
            if (!encodingStarted) return;
            const nowMs = tickAvailableMs();
            if (nowMs <= lastMs + 100) {
                stalled += 1;
            } else {
                stalled = 0;
                lastMs = nowMs;
            }
            // ~2.5s without buffer progress after encode → kill MediaRecorder, keep preview.
            if (stalled >= 5) {
                stopFreezeWatchdog();
                try {
                    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                        mediaRecorder.ondataavailable = null;
                        mediaRecorder.onerror = null;
                        mediaRecorder.onstop = null;
                        mediaRecorder.stop();
                    }
                } catch {
                    // ignore
                }
                mediaRecorder = null;
                encodingStarted = false;
                encodingReady.value = false;
                releaseRecordStream();
                error.value = 'Gravação travou neste iPhone. Preview e keep-alive seguem ativos — pare e tente de novo.';
            }
        }, 500);
    }

    function stopFreezeWatchdog() {
        clearInterval(freezeWatchdog);
        freezeWatchdog = null;
    }

    /** Preview + buffer clock only. Does NOT start MediaRecorder. */
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
            previousSegment = null;
            shouldKeepRecording = true;
            encodingStarted = false;
            operationChain = Promise.resolve();
            sequence = 0;
            recordingStartedAt = Date.now();
            isRecording.value = true;
            startAvailableTimer();

            await nextTick();
            attachPreview();
            await wait(100);
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

    /** Start MediaRecorder after session/heartbeat are alive. */
    async function startEncoding() {
        if (!isRecording.value || !mediaStream) return false;
        if (encodingStarted) return true;

        try {
            await nextTick();
            attachPreview();
            const video = previewEl.value;
            if (video) {
                const readyDeadline = Date.now() + 3000;
                while (Date.now() < readyDeadline && video.readyState < 2) {
                    await wait(100);
                    attachPreview();
                }
            }

            releaseRecordStream();
            await wait(apple ? 300 : 0);
            startRecorder();
            startSegmentTimer();
            encodingStarted = true;
            encodingReady.value = true;
            tickAvailableMs();
            if (apple) startFreezeWatchdog();
            return true;
        } catch (err) {
            encodingReady.value = false;
            error.value = err?.message || 'Não foi possível iniciar a gravação de vídeo.';
            return false;
        }
    }

    async function snapshot() {
        return runExclusive(async () => {
            stopSegmentTimer();
            try {
                const { blob, durationMs, startedAt } = await finalizeCurrentSegment();
                const endedAt = Date.now();

                if (shouldKeepRecording && encodingStarted) {
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
        if (!encodingStarted) return false;
        const minMs = minClipSeconds * 1000;
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
        encodingStarted = false;
        encodingReady.value = false;
        stopSegmentTimer();
        stopAvailableTimer();
        stopFreezeWatchdog();
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
        releaseRecordStream();

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
