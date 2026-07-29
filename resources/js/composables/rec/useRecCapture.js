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

function pickMimeType() {
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

    let stream = null;
    let recorder = null;
    let chunks = [];
    let sequence = 0;
    let segmentStartedAt = 0;
    let recordingStartedAt = 0;
    let segmentTimer = null;
    let watchdogTimer = null;
    let wakeLock = null;
    let keepRecording = false;
    let operationChain = Promise.resolve();
    const mutedSince = new Map();
    const segmentMs = config.segment_seconds * 1000;

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

    function attachPreview() {
        if (!previewEl.value || !stream) return;
        previewEl.value.srcObject = stream;
        previewEl.value.muted = true;
        previewEl.value.playsInline = true;
        previewEl.value.play().catch(() => {});
    }

    async function acquireWakeLock() {
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
        return {
            ...(mimeType ? { mimeType } : {}),
            videoBitsPerSecond: 1_200_000,
            ...(hasAudio.value ? { audioBitsPerSecond: 96_000 } : {}),
        };
    }

    function startRecorder() {
        if (!stream || !keepRecording) return;

        chunks = [];
        segmentStartedAt = Date.now();
        const mimeType = pickMimeType();
        recorder = new MediaRecorder(stream, recorderOptions(mimeType));
        recorder.ondataavailable = (event) => {
            if (event.data?.size) chunks.push(event.data);
        };
        recorder.onerror = () => {
            error.value = 'A gravação da câmera foi interrompida.';
        };
        recorder.start(1_000);
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

            const finish = async () => {
                if (settled) return;
                settled = true;
                await wait(FLUSH_MS);
                const endedAt = Date.now();
                const blob = chunks.length
                    ? new Blob(chunks, {
                        type: (activeRecorder.mimeType || chunks[0]?.type || 'video/webm').split(';')[0],
                    })
                    : null;
                recorder = null;
                chunks = [];
                resolve(blob?.size ? { blob, startedAt, endedAt } : null);
            };

            activeRecorder.onstop = finish;
            try {
                activeRecorder.requestData();
            } catch {
                // Some MediaRecorder implementations do not support requestData here.
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
        await options.onSegment?.(segment);
        return segment;
    }

    async function rotate() {
        return runExclusive(async () => {
            if (!recorder || recorder.state !== 'recording') return null;
            const finalized = await finalizeRecorder();
            const segment = await persistFinalized(finalized);
            if (keepRecording) startRecorder();
            return segment;
        });
    }

    function startTimers() {
        segmentTimer = setInterval(() => rotate().catch(() => {}), segmentMs);
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
        }, WATCHDOG_MS);
    }

    function stopTimers() {
        clearInterval(segmentTimer);
        clearInterval(watchdogTimer);
        segmentTimer = null;
        watchdogTimer = null;
    }

    async function getMediaStream() {
        const attempts = [
            {
                audio: true,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    aspectRatio: { ideal: 16 / 9 },
                    frameRate: { ideal: 24, max: 30 },
                },
            },
            {
                audio: true,
                video: { facingMode: { ideal: 'environment' } },
            },
            {
                audio: false,
                video: { facingMode: { ideal: 'environment' } },
            },
            {
                audio: false,
                video: true,
            },
        ];

        let lastError = null;

        for (const constraints of attempts) {
            try {
                hasAudio.value = constraints.audio === true;
                return await navigator.mediaDevices.getUserMedia(constraints);
            } catch (attemptError) {
                lastError = attemptError;
            }
        }

        throw lastError || new Error('getUserMedia failed');
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
            const existing = await store.getSegments(sessionUuid());
            sequence = existing.reduce((max, item) => Math.max(max, item.sequence || 0), 0);
            keepRecording = true;
            recordingStartedAt = Date.now();
            operationChain = Promise.resolve();
            startRecorder();
            startTimers();
            isRecording.value = true;
            attachPreview();
            await nextTick();
            attachPreview();
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

    async function stop() {
        keepRecording = false;
        stopTimers();
        await runExclusive(async () => persistFinalized(await finalizeRecorder()));
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        mutedSince.clear();
        if (previewEl.value) previewEl.value.srcObject = null;
        await wakeLock?.release?.().catch(() => {});
        wakeLock = null;
        isRecording.value = false;
        recordingStartedAt = 0;
    }

    async function listLocalSegmentsInWindow(from, until = Date.now()) {
        if (isRecording.value) await rotate();
        const fromMs = typeof from === 'string' ? Date.parse(from) : Number(from);
        const untilMs = typeof until === 'string' ? Date.parse(until) : Number(until);
        // Include orphan segments created before the session UUID was assigned.
        const segments = await store.getSegments();
        const currentSession = sessionUuid();
        return segments.filter((segment) =>
            (!currentSession || !segment.sessionUuid || segment.sessionUuid === currentSession)
            && segment.endedAt >= (Number.isFinite(fromMs) ? fromMs : 0)
            && segment.startedAt <= (Number.isFinite(untilMs) ? untilMs : Date.now()));
    }

    async function getAvailableMs() {
        if (isRecording.value && recordingStartedAt) {
            return Math.min(
                config.local_retention_seconds * 1000,
                Math.max(0, Date.now() - recordingStartedAt),
            );
        }

        const segments = await store.getSegments();
        const currentSession = sessionUuid();
        const scoped = segments.filter((segment) =>
            !currentSession || !segment.sessionUuid || segment.sessionUuid === currentSession);
        if (!scoped.length) return 0;

        const earliest = Math.min(...scoped.map((item) => item.startedAt));
        const latest = Math.max(...scoped.map((item) => item.endedAt));
        return Math.min(config.local_retention_seconds * 1000, Math.max(0, latest - earliest));
    }

    async function handleVisibilityChange() {
        if (isRecording.value && document.visibilityState === 'visible' && !wakeLock) {
            await acquireWakeLock();
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
        stop,
        isRecording,
        isSupported,
        error,
        previewEl,
        hasAudio,
        getAvailableMs,
        listLocalSegmentsInWindow,
    };
}
