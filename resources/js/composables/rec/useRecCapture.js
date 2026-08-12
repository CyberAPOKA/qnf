import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useRecConfig } from './recConfig';
import { useRecSegmentStore } from './useRecSegmentStore';

const FLUSH_MS = 150;
const WATCHDOG_MS = 2_000;

function wait(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
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
        // Prefer browser default on iOS; forced mp4 is flaky across versions.
        if (MediaRecorder.isTypeSupported('video/mp4')) return 'video/mp4';
        return '';
    }

    return [
        'video/webm;codecs=vp8,opus',
        'video/webm;codecs=vp8',
        'video/webm',
        'video/mp4',
    ].find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

export function useRecCapture(options = {}) {
    const config = useRecConfig(options.config);
    const store = options.store || useRecSegmentStore();
    const apple = isAppleMobile();

    const isRecording = ref(false);
    const isSupported = ref(
        typeof window !== 'undefined'
        && !!navigator.mediaDevices?.getUserMedia
        && typeof MediaRecorder !== 'undefined',
    );
    const error = ref(null);
    const previewEl = ref(null);
    const hasAudio = ref(!apple);
    const availableMs = ref(0);
    const encodingReady = ref(false);

    let cameraStream = null;
    let recordStream = null;
    let recorder = null;
    let chunks = [];
    let sequence = 0;
    let segmentStartedAt = 0;
    let recordingStartedAt = 0;
    let segmentTimer = null;
    let watchdogTimer = null;
    let availableTimer = null;
    let canvasPump = null;
    let recordCanvas = null;
    let wakeLock = null;
    let keepRecording = false;
    let encodingStarted = false;
    let rotating = false;
    let operationChain = Promise.resolve();
    const mutedSince = new Map();

    // iOS: shorter rolling windows reduce memory pressure without rotating too often.
    const segmentMs = apple
        ? Math.max(10_000, Math.min(20_000, (config.segment_seconds || 5) * 1000 * 2))
        : Math.max(1_000, config.segment_seconds * 1000);
    const maxLocalSegments = apple
        ? Math.max(3, Math.ceil((config.buffer_seconds || 30) / (segmentMs / 1000)) + 1)
        : 24;

    function sessionUuid() {
        return typeof options.sessionUuid === 'function'
            ? options.sessionUuid()
            : options.sessionUuid?.value || options.sessionUuid || null;
    }

    function cameraTag() {
        return typeof options.cameraTag === 'function'
            ? options.cameraTag()
            : options.cameraTag?.value || options.cameraTag || null;
    }

    function runExclusive(task) {
        const next = operationChain.then(task, task);
        operationChain = next.catch(() => {});
        return next;
    }

    function tickAvailableMs() {
        if (!recordingStartedAt) {
            availableMs.value = 0;
            return 0;
        }
        const retentionMs = Math.max(1, Number(config.local_retention_seconds) || 180) * 1000;
        const ms = Math.min(retentionMs, Math.max(0, Date.now() - recordingStartedAt));
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

    async function attachPreview() {
        const video = previewEl.value;
        if (!video || !cameraStream) return false;
        try {
            video.setAttribute('playsinline', 'true');
            video.setAttribute('webkit-playsinline', 'true');
            video.muted = true;
            video.playsInline = true;
            video.autoplay = true;
            if (video.srcObject !== cameraStream) {
                video.srcObject = cameraStream;
            }
            await video.play().catch(() => {});
            return video.readyState >= 2;
        } catch {
            return false;
        }
    }

    async function waitForPreview(timeoutMs = 5_000) {
        const started = Date.now();
        while (Date.now() - started < timeoutMs) {
            await nextTick();
            const ok = await attachPreview();
            const video = previewEl.value;
            if (ok && video && video.videoWidth > 0) return true;
            await wait(100);
        }
        return !!(previewEl.value?.srcObject);
    }

    function stopCanvasPump() {
        if (canvasPump) {
            cancelAnimationFrame(canvasPump);
            canvasPump = null;
        }
    }

    function buildClonedRecordStream() {
        if (!cameraStream) return null;
        const tracks = cameraStream.getVideoTracks().map((track) => track.clone());
        if (!tracks.length) return null;
        return new MediaStream(tracks);
    }

    function buildCanvasRecordStream() {
        const video = previewEl.value;
        if (!video || typeof document === 'undefined') return null;
        if (typeof HTMLCanvasElement === 'undefined' || !HTMLCanvasElement.prototype.captureStream) {
            return null;
        }

        recordCanvas = recordCanvas || document.createElement('canvas');
        const width = video.videoWidth || 640;
        const height = video.videoHeight || 360;
        recordCanvas.width = width;
        recordCanvas.height = height;
        const ctx = recordCanvas.getContext('2d', { alpha: false });
        if (!ctx) return null;

        stopCanvasPump();
        const fps = 15;
        let lastDraw = 0;
        const draw = (now) => {
            if (!keepRecording) return;
            if (now - lastDraw >= 1000 / fps) {
                lastDraw = now;
                try {
                    ctx.drawImage(video, 0, 0, width, height);
                } catch {
                    // drawImage can throw briefly while the video is resizing.
                }
            }
            canvasPump = requestAnimationFrame(draw);
        };
        canvasPump = requestAnimationFrame(draw);
        return recordCanvas.captureStream(fps);
    }

    function releaseRecordStream() {
        stopCanvasPump();
        recordStream?.getTracks?.().forEach((track) => {
            try {
                track.stop();
            } catch {
                // ignore
            }
        });
        recordStream = null;
    }

    function ensureRecordStream() {
        if (recordStream) return recordStream;

        if (apple) {
            // Prefer clone: same frames as camera, isolated from the <video> element.
            recordStream = buildClonedRecordStream() || buildCanvasRecordStream();
        } else {
            recordStream = cameraStream;
        }

        return recordStream;
    }

    async function acquireWakeLock() {
        if (apple) return;
        if (!navigator.wakeLock?.request || document.visibilityState !== 'visible') return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => {
                wakeLock = null;
            }, { once: true });
        } catch {
            // optional
        }
    }

    async function pruneOldSegments() {
        try {
            const currentSession = sessionUuid();
            const segments = await store.getSegments(currentSession);
            const overflow = segments.length - maxLocalSegments;
            if (overflow <= 0) return;
            await Promise.all(
                segments.slice(0, overflow).map((segment) => store.deleteSegment(segment.uuid).catch(() => {})),
            );
        } catch {
            // best-effort
        }
    }

    function bindRecorder(activeRecorder) {
        activeRecorder.ondataavailable = (event) => {
            if (event.data?.size) chunks.push(event.data);
        };
        activeRecorder.onerror = () => {
            error.value = 'A gravação da câmera foi interrompida.';
        };
    }

    function createMediaRecorder(target) {
        const mimeType = pickMimeType();
        const attempts = apple
            ? [
                () => new MediaRecorder(target),
                () => (mimeType ? new MediaRecorder(target, { mimeType }) : null),
            ]
            : [
                () => new MediaRecorder(target, {
                    ...(mimeType ? { mimeType } : {}),
                    videoBitsPerSecond: 1_200_000,
                    ...(hasAudio.value ? { audioBitsPerSecond: 96_000 } : {}),
                }),
                () => (mimeType ? new MediaRecorder(target, { mimeType }) : null),
                () => new MediaRecorder(target),
            ];

        let lastError = null;
        for (const attempt of attempts) {
            try {
                const created = attempt();
                if (created) return created;
            } catch (recorderError) {
                lastError = recorderError;
            }
        }
        throw lastError || new Error('MediaRecorder failed');
    }

    function startRecorder() {
        if (!keepRecording) return;
        const target = ensureRecordStream();
        if (!target) {
            throw new Error('Stream de gravação indisponível.');
        }

        chunks = [];
        segmentStartedAt = Date.now();
        recorder = createMediaRecorder(target);
        bindRecorder(recorder);

        // timeslice freezes many iOS builds; Android benefits from it.
        if (apple) recorder.start();
        else recorder.start(1_000);
    }

    function finalizeRecorder() {
        return new Promise((resolve) => {
            if (!recorder || recorder.state === 'inactive') {
                resolve(null);
                return;
            }

            const activeRecorder = recorder;
            const startedAt = segmentStartedAt;
            let settled = false;
            let hangTimer = null;

            const finish = async () => {
                if (settled) return;
                settled = true;
                clearTimeout(hangTimer);
                await wait(FLUSH_MS);
                const endedAt = Date.now();
                const blob = chunks.length
                    ? new Blob(chunks, {
                        type: (activeRecorder.mimeType || chunks[0]?.type || 'video/mp4').split(';')[0],
                    })
                    : null;
                recorder = null;
                chunks = [];
                resolve(blob?.size ? { blob, startedAt, endedAt } : null);
            };

            hangTimer = setTimeout(() => finish(), apple ? 2_500 : 3_000);
            activeRecorder.onstop = finish;
            if (!apple) {
                try {
                    activeRecorder.requestData();
                } catch {
                    // ignore
                }
            }
            try {
                activeRecorder.stop();
            } catch {
                finish();
            }
        });
    }

    async function persistFinalized(finalized) {
        if (!finalized?.blob?.size) return null;

        sequence += 1;
        try {
            const segment = await store.putSegment({
                uuid: makeUuid(),
                sessionUuid: sessionUuid(),
                cameraTag: cameraTag(),
                sequence,
                startedAt: finalized.startedAt,
                endedAt: finalized.endedAt,
                durationMs: Math.max(1, finalized.endedAt - finalized.startedAt),
                mimeType: finalized.blob.type,
                bytes: finalized.blob.size,
                blob: finalized.blob,
                uploadVerified: false,
            });
            await pruneOldSegments();
            // Never await upload here — it must not block rotate/heartbeat.
            Promise.resolve(options.onSegment?.(segment)).catch(() => {});
            return segment;
        } catch {
            error.value = 'Não foi possível guardar o segmento localmente.';
            return null;
        }
    }

    async function rotate() {
        if (rotating || !encodingStarted) return null;
        rotating = true;
        try {
            return await runExclusive(async () => {
                if (!recorder || recorder.state !== 'recording') return null;
                const finalized = await finalizeRecorder();
                const segment = await persistFinalized(finalized);
                if (keepRecording && encodingStarted) {
                    // Yield to the browser before re-starting MediaRecorder (critical on iOS).
                    await wait(apple ? 250 : 50);
                    startRecorder();
                }
                return segment;
            });
        } finally {
            rotating = false;
        }
    }

    function startTimers() {
        stopTimers();
        segmentTimer = setInterval(() => {
            if (document.visibilityState === 'hidden') return;
            rotate().catch(() => {});
        }, segmentMs);

        watchdogTimer = setInterval(() => {
            if (!cameraStream) return;
            cameraStream.getTracks().forEach((track) => {
                if (track.readyState === 'ended') {
                    error.value = 'A câmera foi desconectada.';
                    return;
                }
                if (track.muted) {
                    const since = mutedSince.get(track) || Date.now();
                    mutedSince.set(track, since);
                    if (Date.now() - since > 10_000) {
                        error.value = 'Câmera sem sinal.';
                    }
                } else {
                    mutedSince.delete(track);
                }
            });
            attachPreview();
            tickAvailableMs();
        }, WATCHDOG_MS);
    }

    function stopTimers() {
        clearInterval(segmentTimer);
        clearInterval(watchdogTimer);
        segmentTimer = null;
        watchdogTimer = null;
    }

    function isFrontCameraTrack(track) {
        if (!track) return false;
        const label = (track.label || '').toLowerCase();
        const facing = track.getSettings?.()?.facingMode;
        if (facing === 'environment') return false;
        if (facing === 'user') return true;
        return label.includes('front')
            || label.includes('frontal')
            || /\buser\b/.test(label)
            || label.includes('facetime')
            || label.includes('face time');
    }

    async function findRearDeviceId() {
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const rear = devices.find((device) => {
                if (device.kind !== 'videoinput') return false;
                const label = (device.label || '').toLowerCase();
                return label.includes('back')
                    || label.includes('rear')
                    || label.includes('trás')
                    || label.includes('traseira')
                    || /\benvironment\b/.test(label);
            });
            return rear?.deviceId || null;
        } catch {
            return null;
        }
    }

    async function getMediaStream() {
        const videoOnlyAttempts = [
            { audio: false, video: { facingMode: { exact: 'environment' } } },
            { audio: false, video: { facingMode: 'environment' } },
            { audio: false, video: { facingMode: { ideal: 'environment' } } },
        ];

        let lastError = null;

        for (const constraints of videoOnlyAttempts) {
            try {
                const media = await navigator.mediaDevices.getUserMedia(constraints);
                if (isFrontCameraTrack(media.getVideoTracks()[0])) {
                    media.getTracks().forEach((track) => track.stop());
                    continue;
                }
                hasAudio.value = false;
                return media;
            } catch (attemptError) {
                lastError = attemptError;
            }
        }

        const rearDeviceId = await findRearDeviceId();
        if (rearDeviceId) {
            try {
                const media = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: { deviceId: { exact: rearDeviceId } },
                });
                if (!isFrontCameraTrack(media.getVideoTracks()[0])) {
                    hasAudio.value = false;
                    return media;
                }
                media.getTracks().forEach((track) => track.stop());
            } catch (attemptError) {
                lastError = attemptError;
            }
        }

        if (!apple) {
            try {
                const media = await navigator.mediaDevices.getUserMedia({
                    audio: true,
                    video: {
                        facingMode: { exact: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        frameRate: { ideal: 24, max: 30 },
                    },
                });
                if (!isFrontCameraTrack(media.getVideoTracks()[0])) {
                    hasAudio.value = true;
                    return media;
                }
                media.getTracks().forEach((track) => track.stop());
            } catch (attemptError) {
                lastError = attemptError;
            }
        }

        throw lastError || new Error('Não foi possível abrir a câmera traseira.');
    }

    async function start() {
        error.value = null;
        encodingReady.value = false;
        if (!isSupported.value) {
            error.value = 'Gravação não suportada neste navegador.';
            return false;
        }
        if (isRecording.value) return true;

        try {
            cameraStream = await getMediaStream();
            keepRecording = true;
            encodingStarted = false;
            recordingStartedAt = Date.now();
            operationChain = Promise.resolve();
            isRecording.value = true;
            startAvailableTimer();
            await nextTick();
            await waitForPreview();
            await acquireWakeLock();
            return true;
        } catch (captureError) {
            const name = captureError?.name || '';
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                error.value = 'Permissão da câmera negada. Libere câmera/microfone nas configurações do navegador.';
            } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                error.value = 'Nenhuma câmera foi encontrada neste aparelho.';
            } else if (name === 'NotReadableError' || name === 'TrackStartError') {
                error.value = 'A câmera está em uso por outro aplicativo.';
            } else if (name === 'SecurityError') {
                error.value = 'Acesso à câmera bloqueado. Abra o site em HTTPS no Safari (não use o browser interno do Instagram/WhatsApp).';
            } else {
                error.value = captureError?.message || 'Não foi possível acessar a câmera.';
            }
            await stop();
            return false;
        }
    }

    async function startEncoding() {
        if (!isRecording.value || !cameraStream || encodingStarted) {
            return encodingStarted;
        }

        try {
            await waitForPreview(3_000);

            let existing = [];
            try {
                existing = await store.getSegments(sessionUuid());
            } catch {
                existing = [];
            }
            sequence = existing.reduce((max, item) => Math.max(max, item.sequence || 0), 0);
            keepRecording = true;

            // Let the UI/heartbeat breathe before touching MediaRecorder.
            await wait(apple ? 400 : 0);

            // Build isolated record stream BEFORE starting MediaRecorder.
            releaseRecordStream();
            if (!ensureRecordStream()) {
                throw new Error('Falha ao preparar stream de gravação.');
            }

            // Yield once more so Vue can paint buffer ticks.
            await wait(0);
            startRecorder();
            startTimers();
            encodingStarted = true;
            encodingReady.value = true;
            tickAvailableMs();
            return true;
        } catch (encodeError) {
            encodingReady.value = false;
            releaseRecordStream();
            error.value = encodeError?.message || 'Não foi possível iniciar a gravação de vídeo.';
            return false;
        }
    }

    async function stop() {
        keepRecording = false;
        encodingStarted = false;
        encodingReady.value = false;
        stopTimers();
        stopAvailableTimer();
        try {
            await runExclusive(async () => persistFinalized(await finalizeRecorder()));
        } catch {
            // best-effort
        }
        releaseRecordStream();
        cameraStream?.getTracks?.().forEach((track) => track.stop());
        cameraStream = null;
        mutedSince.clear();
        if (previewEl.value) previewEl.value.srcObject = null;
        await wakeLock?.release?.().catch(() => {});
        wakeLock = null;
        isRecording.value = false;
        recordingStartedAt = 0;
        availableMs.value = 0;
    }

    async function listLocalSegmentsInWindow(from, until = Date.now()) {
        if (isRecording.value && encodingStarted) await rotate();
        const fromMs = typeof from === 'string' ? Date.parse(from) : Number(from);
        const untilMs = typeof until === 'string' ? Date.parse(until) : Number(until);
        try {
            const segments = await store.getSegments();
            const currentSession = sessionUuid();
            return segments.filter((segment) =>
                (!currentSession || !segment.sessionUuid || segment.sessionUuid === currentSession)
                && segment.endedAt >= (Number.isFinite(fromMs) ? fromMs : 0)
                && segment.startedAt <= (Number.isFinite(untilMs) ? untilMs : Date.now()));
        } catch {
            return [];
        }
    }

    function getAvailableMsSync() {
        return tickAvailableMs();
    }

    async function getAvailableMs() {
        return getAvailableMsSync();
    }

    async function handleVisibilityChange() {
        if (isRecording.value && document.visibilityState === 'visible') {
            await attachPreview();
            if (!wakeLock) await acquireWakeLock();
        }
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', handleVisibilityChange);
    }

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', handleVisibilityChange);
        stop();
    });

    return {
        start,
        startEncoding,
        stop,
        isRecording,
        isSupported,
        error,
        previewEl,
        hasAudio,
        availableMs,
        encodingReady,
        attachPreview,
        getAvailableMs,
        getAvailableMsSync,
        listLocalSegmentsInWindow,
    };
}
