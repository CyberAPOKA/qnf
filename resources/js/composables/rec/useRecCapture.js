import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useRecConfig } from './recConfig';

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
 * In-memory circular buffer. Never touches IndexedDB.
 * availableMs is wall-clock from recording start (always advances while recording).
 */
export function useRecCapture(options = {}) {
    const config = useRecConfig(options.config);
    const apple = isAppleMobile();
    const bufferSeconds = Math.max(10, Number(config.buffer_seconds) || 30);
    const bufferMs = bufferSeconds * 1000;
    const minClipSeconds = Math.max(3, Math.min(bufferSeconds - 5, 8));

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
    const bytesBuffered = ref(0);

    let mediaStream = null;
    let mediaRecorder = null;
    let mimeType = '';
    let recordingStartedAt = 0;
    let ticker = null;
    let wakeLock = null;
    let sequence = 0;
    let shouldKeepRecording = false;
    let usesTimeslice = !apple;

    /** @type {{ blob: Blob, at: number }[]} */
    const parts = [];

    function pickMimeType() {
        if (typeof MediaRecorder === 'undefined') return '';
        if (apple) return '';
        const candidates = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp8',
            'video/webm',
            'video/mp4',
        ];
        return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
    }

    function blobType() {
        return (mimeType || parts[0]?.blob?.type || 'video/webm').split(';')[0];
    }

    function trimParts() {
        if (!recordingStartedAt) return;
        const cutoff = Date.now() - bufferMs - 2000;
        while (parts.length > 2 && parts[0].at < cutoff) {
            parts.shift();
        }
        bytesBuffered.value = parts.reduce((sum, part) => sum + (part.blob?.size || 0), 0);
    }

    function tickAvailable() {
        if (!recordingStartedAt || !shouldKeepRecording) {
            availableMs.value = 0;
            return 0;
        }
        const ms = Math.min(bufferMs, Math.max(0, Date.now() - recordingStartedAt));
        availableMs.value = ms;
        return ms;
    }

    function startTicker() {
        stopTicker();
        tickAvailable();
        const interval = setInterval(tickAvailable, 250);
        let raf = 0;
        const pulse = () => {
            tickAvailable();
            if (shouldKeepRecording) {
                raf = requestAnimationFrame(pulse);
                ticker = { raf, interval };
            }
        };
        raf = requestAnimationFrame(pulse);
        ticker = { raf, interval };
    }

    function stopTicker() {
        if (ticker?.raf) cancelAnimationFrame(ticker.raf);
        if (ticker?.interval) clearInterval(ticker.interval);
        ticker = null;
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

    async function requestWakeLock() {
        try {
            wakeLock = await navigator.wakeLock?.request?.('screen');
            wakeLock?.addEventListener?.('release', () => {
                wakeLock = null;
            });
        } catch {
            wakeLock = null;
        }
    }

    function releaseWakeLock() {
        try {
            wakeLock?.release?.();
        } catch {
            // ignore
        }
        wakeLock = null;
    }

    async function getMediaStream() {
        const video = {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1280 },
            height: { ideal: 720 },
        };

        if (apple) {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video });
            hasAudio.value = false;
            return stream;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: { ...video, frameRate: { ideal: 24, max: 30 } },
            });
            hasAudio.value = true;
            return stream;
        } catch {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video });
            hasAudio.value = false;
            return stream;
        }
    }

    function pushChunk(blob) {
        if (!blob?.size) return;
        parts.push({ blob, at: Date.now() });
        trimParts();
    }

    function startRecorder() {
        if (!mediaStream || !shouldKeepRecording) return;

        const opts = {};
        if (mimeType) opts.mimeType = mimeType;
        if (!apple) {
            opts.videoBitsPerSecond = 1_200_000;
            if (hasAudio.value) opts.audioBitsPerSecond = 96_000;
        }

        try {
            mediaRecorder = Object.keys(opts).length
                ? new MediaRecorder(mediaStream, opts)
                : new MediaRecorder(mediaStream);
        } catch {
            mediaRecorder = new MediaRecorder(mediaStream);
        }

        mediaRecorder.ondataavailable = (event) => {
            pushChunk(event.data);
        };
        mediaRecorder.onerror = () => {
            error.value = 'Erro na gravação.';
        };

        if (usesTimeslice) {
            mediaRecorder.start(TIMESLICE_MS);
        } else {
            mediaRecorder.start();
        }
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
            mediaStream = await getMediaStream();
            mimeType = pickMimeType();
            parts.length = 0;
            bytesBuffered.value = 0;
            sequence = 0;
            shouldKeepRecording = true;
            recordingStartedAt = Date.now();
            availableMs.value = 0;

            attachPreview(mediaStream);
            startRecorder();
            startTicker();
            void requestWakeLock();

            isRecording.value = true;
            encodingReady.value = true;
            tickAvailable();

            // If timeslice yields nothing on this device, fall back to continuous mode.
            if (usesTimeslice) {
                void (async () => {
                    await wait(1800);
                    if (!shouldKeepRecording) return;
                    if (parts.length === 0 && mediaRecorder?.state === 'recording') {
                        usesTimeslice = false;
                        try {
                            mediaRecorder.ondataavailable = null;
                            mediaRecorder.stop();
                        } catch {
                            // ignore
                        }
                        startRecorder();
                    }
                })();
            }

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

    async function flushRecorder() {
        if (!mediaRecorder || mediaRecorder.state !== 'recording') return;
        if (typeof mediaRecorder.requestData === 'function' && usesTimeslice) {
            try {
                mediaRecorder.requestData();
                await wait(120);
            } catch {
                // ignore
            }
            return;
        }

        // Continuous mode (typical iOS): must stop to obtain the blob, then restart.
        await new Promise((resolve) => {
            const recorder = mediaRecorder;
            let settled = false;
            const finish = () => {
                if (settled) return;
                settled = true;
                resolve();
            };
            const hang = setTimeout(finish, 2500);
            recorder.onstop = () => {
                clearTimeout(hang);
                finish();
            };
            try {
                recorder.stop();
            } catch {
                clearTimeout(hang);
                finish();
            }
        });

        mediaRecorder = null;
        if (shouldKeepRecording) {
            startRecorder();
        }
    }

    async function snapshot() {
        if (!shouldKeepRecording && !parts.length) return null;

        await flushRecorder();
        trimParts();

        if (!parts.length) return null;

        const blob = new Blob(parts.map((part) => part.blob), { type: blobType() });
        if (!blob.size) return null;

        const endedAt = Date.now();
        const durationMs = Math.min(bufferMs, Math.max(1000, endedAt - (parts[0]?.at || recordingStartedAt || endedAt)));
        const startedAt = endedAt - durationMs;

        return {
            parts: [{ blob, startedAt, endedAt, durationMs }],
            durationSeconds: Math.round(durationMs / 1000),
        };
    }

    function hasBuffer() {
        return availableMs.value >= minClipSeconds * 1000 || bytesBuffered.value > 0;
    }

    function nextSequence() {
        sequence += 1;
        return sequence;
    }

    function stop() {
        shouldKeepRecording = false;
        encodingReady.value = false;
        stopTicker();
        releaseWakeLock();

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
        parts.length = 0;
        bytesBuffered.value = 0;
        recordingStartedAt = 0;
        availableMs.value = 0;
        mediaStream?.getTracks?.().forEach((track) => track.stop());
        mediaStream = null;
        if (previewEl.value) previewEl.value.srcObject = null;
        isRecording.value = false;
        usesTimeslice = !apple;
    }

    function getAvailableMsSync() {
        return tickAvailable();
    }

    async function getAvailableMs() {
        return getAvailableMsSync();
    }

    onBeforeUnmount(() => stop());

    return {
        start,
        startWithEncoding: start,
        startEncoding: async () => isRecording.value,
        stop,
        snapshot,
        hasBuffer,
        listLocalSegmentsInWindow: async () => [],
        nextSequence,
        makeUuid,
        isRecording,
        encodingReady,
        isSupported,
        error,
        previewEl,
        hasAudio,
        availableMs,
        bytesBuffered,
        attachPreview,
        getAvailableMs,
        getAvailableMsSync,
        bufferSeconds,
        minClipSeconds,
    };
}
