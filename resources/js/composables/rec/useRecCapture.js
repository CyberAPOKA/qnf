import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useRecConfig } from './recConfig';
import { useRecSegmentStore } from './useRecSegmentStore';

const FLUSH_MS = 120;
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

    // On iOS, an empty mimeType (browser default) is often more stable than forcing mp4.
    if (isAppleMobile()) {
        const types = ['', 'video/mp4'];
        for (const type of types) {
            if (!type || MediaRecorder.isTypeSupported(type)) return type;
        }
        return '';
    }

    const types = [
        'video/webm;codecs=vp8,opus',
        'video/webm;codecs=vp8',
        'video/webm',
        'video/mp4',
    ];
    return types.find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

export function useRecCapture(options = {}) {
    const config = useRecConfig(options.config);
    const store = options.store || useRecSegmentStore();
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

    let stream = null;
    let recorder = null;
    let chunks = [];
    let sequence = 0;
    let segmentStartedAt = 0;
    let recordingStartedAt = 0;
    let segmentTimer = null;
    let watchdogTimer = null;
    let availableTimer = null;
    let wakeLock = null;
    let keepRecording = false;
    let operationChain = Promise.resolve();
    const mutedSince = new Map();
    const segmentMs = Math.max(
        config.segment_seconds * 1000,
        isAppleMobile() ? 60_000 : config.segment_seconds * 1000,
    );
    const maxLocalSegments = isAppleMobile() ? 3 : 24;
    let encodingStarted = false;
    let rotating = false;

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
        const ms = Math.min(
            Math.max(1, Number(config.local_retention_seconds) || 180) * 1000,
            Math.max(0, Date.now() - recordingStartedAt),
        );
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

    async function attachPreview() {
        const video = previewEl.value;
        if (!video || !stream) return false;

        try {
            video.setAttribute('playsinline', 'true');
            video.setAttribute('webkit-playsinline', 'true');
            video.muted = true;
            video.autoplay = true;
            if (video.srcObject !== stream) {
                video.srcObject = stream;
            }
            const playResult = video.play();
            if (playResult?.then) await playResult.catch(() => {});
            return true;
        } catch {
            return false;
        }
    }

    async function waitForPreview(timeoutMs = 4_000) {
        const started = Date.now();
        while (Date.now() - started < timeoutMs) {
            await attachPreview();
            const video = previewEl.value;
            if (video && video.srcObject && video.readyState >= 2) return true;
            await wait(200);
        }
        return !!(previewEl.value?.srcObject);
    }

    async function acquireWakeLock() {
        if (isAppleMobile()) return;
        if (!navigator.wakeLock?.request || document.visibilityState !== 'visible') return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => {
                wakeLock = null;
            }, { once: true });
        } catch {
            // Wake Lock is an enhancement; recording remains usable without it.
        }
    }

    function recorderOptions(mimeType) {
        // Bitrate hints crash or freeze some iOS Safari builds — keep options minimal.
        if (isAppleMobile()) {
            return mimeType ? { mimeType } : {};
        }

        return {
            ...(mimeType ? { mimeType } : {}),
            videoBitsPerSecond: 1_200_000,
            ...(hasAudio.value ? { audioBitsPerSecond: 96_000 } : {}),
        };
    }

    async function pruneOldSegments() {
        try {
            const currentSession = sessionUuid();
            const segments = await store.getSegments(currentSession);
            const overflow = segments.length - maxLocalSegments;
            if (overflow <= 0) return;
            const doomed = segments.slice(0, overflow);
            await Promise.all(doomed.map((segment) => store.deleteSegment(segment.uuid).catch(() => {})));
        } catch {
            // Retention is best-effort.
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

    function startRecorder() {
        if (!stream || !keepRecording) return;

        chunks = [];
        segmentStartedAt = Date.now();
        const mimeType = pickMimeType();
        const attempts = isAppleMobile()
            ? [
                () => new MediaRecorder(stream),
                () => new MediaRecorder(stream, mimeType ? { mimeType } : {}),
            ]
            : [
                () => new MediaRecorder(stream, recorderOptions(mimeType)),
                () => new MediaRecorder(stream, mimeType ? { mimeType } : {}),
                () => new MediaRecorder(stream),
            ];

        let created = null;
        let lastError = null;
        for (const attempt of attempts) {
            try {
                created = attempt();
                break;
            } catch (recorderError) {
                lastError = recorderError;
            }
        }

        if (!created) {
            error.value = 'Este navegador não consegue gravar o vídeo da câmera.';
            throw lastError || new Error('MediaRecorder failed');
        }

        recorder = created;
        bindRecorder(recorder);
        // Never use timeslice on iOS — it commonly freezes Safari.
        if (isAppleMobile()) {
            recorder.start();
        } else {
            recorder.start(1_000);
        }
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

            hangTimer = setTimeout(() => finish(), 3_000);
            activeRecorder.onstop = finish;
            if (!isAppleMobile()) {
                try {
                    activeRecorder.requestData();
                } catch {
                    // Some MediaRecorder implementations do not support requestData here.
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
            Promise.resolve(options.onSegment?.(segment)).catch(() => {});
            return segment;
        } catch {
            error.value = 'Não foi possível guardar o segmento localmente. O SAVE pode falhar neste aparelho.';
            return null;
        }
    }

    async function rotate() {
        if (rotating) return null;
        rotating = true;
        try {
            return await runExclusive(async () => {
                if (!recorder || recorder.state !== 'recording') return null;
                const finalized = await finalizeRecorder();
                const segment = await persistFinalized(finalized);
                if (keepRecording) startRecorder();
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
            if (!stream) return;
            stream.getTracks().forEach((track) => {
                if (track.readyState === 'ended') {
                    error.value = 'A câmera ou o microfone foi desconectado.';
                    return;
                }
                if (track.muted) {
                    const since = mutedSince.get(track) || Date.now();
                    mutedSince.set(track, since);
                    if (Date.now() - since > 10_000) {
                        error.value = `${track.kind === 'video' ? 'Câmera' : 'Áudio'} sem sinal.`;
                    }
                } else {
                    mutedSince.delete(track);
                }
            });
            attachPreview();
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
            || label.includes('face time')
            || label.includes('facetime');
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
        const attempts = [
            { audio: false, video: { facingMode: { exact: 'environment' } } },
            { audio: false, video: { facingMode: 'environment' } },
            { audio: false, video: { facingMode: { ideal: 'environment' } } },
        ];

        let lastError = null;

        for (const constraints of attempts) {
            try {
                hasAudio.value = false;
                const media = await navigator.mediaDevices.getUserMedia(constraints);
                if (isFrontCameraTrack(media.getVideoTracks()[0])) {
                    media.getTracks().forEach((track) => track.stop());
                    continue;
                }
                return media;
            } catch (attemptError) {
                lastError = attemptError;
            }
        }

        // After permission, labels are usually available — pick the rear device explicitly.
        const rearDeviceId = await findRearDeviceId();
        if (rearDeviceId) {
            try {
                hasAudio.value = false;
                const media = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: { deviceId: { exact: rearDeviceId } },
                });
                if (!isFrontCameraTrack(media.getVideoTracks()[0])) {
                    return media;
                }
                media.getTracks().forEach((track) => track.stop());
            } catch (attemptError) {
                lastError = attemptError;
            }
        }

        if (!isAppleMobile()) {
            try {
                hasAudio.value = true;
                const media = await navigator.mediaDevices.getUserMedia({
                    audio: true,
                    video: { facingMode: { exact: 'environment' } },
                });
                if (!isFrontCameraTrack(media.getVideoTracks()[0])) {
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
        if (!isSupported.value) {
            error.value = 'Gravação não suportada neste navegador.';
            return false;
        }
        if (isRecording.value) return true;

        try {
            stream = await getMediaStream();
            keepRecording = true;
            encodingStarted = false;
            recordingStartedAt = Date.now();
            operationChain = Promise.resolve();
            isRecording.value = true;
            startAvailableTimer();
            await nextTick();
            await wait(50);
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
                error.value = 'Acesso à câmera bloqueado. Abra o site em HTTPS no navegador do sistema (não use o browser interno do Instagram/WhatsApp).';
            } else {
                error.value = 'Não foi possível acessar a câmera.';
            }
            await stop();
            return false;
        }
    }

    async function startEncoding() {
        if (!isRecording.value || !stream || encodingStarted) return true;

        try {
            await waitForPreview(2_000);
            let existing = [];
            try {
                existing = await store.getSegments(sessionUuid());
            } catch {
                existing = [];
            }
            sequence = existing.reduce((max, item) => Math.max(max, item.sequence || 0), 0);
            keepRecording = true;

            // Isolate MediaRecorder start so a Safari freeze is less likely to race UI.
            await wait(isAppleMobile() ? 1_000 : 0);
            startRecorder();
            startTimers();
            encodingStarted = true;
            tickAvailableMs();
            return true;
        } catch (encodeError) {
            error.value = encodeError?.message || 'Não foi possível iniciar a gravação de vídeo.';
            return false;
        }
    }

    async function stop() {
        keepRecording = false;
        encodingStarted = false;
        stopTimers();
        stopAvailableTimer();
        try {
            await runExclusive(async () => persistFinalized(await finalizeRecorder()));
        } catch {
            // Best-effort finalize on stop.
        }
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
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
        attachPreview,
        getAvailableMs,
        getAvailableMsSync,
        listLocalSegmentsInWindow,
    };
}
