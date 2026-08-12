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
 * iPhone-safe capture:
 * - wall-clock buffer counter updated at most 1x/segundo (never rAF)
 * - no IndexedDB, no wake lock on iOS
 * - MediaRecorder continuous on iOS (stop only on SAVE/stop)
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
    const hasAudio = ref(!apple);
    const availableMs = ref(0);
    const availableSec = ref(0);
    const bytesBuffered = ref(0);

    let mediaStream = null;
    let mediaRecorder = null;
    let mimeType = '';
    let recordingStartedAt = 0;
    let ticker = null;
    let sequence = 0;
    let shouldKeepRecording = false;
    let usesTimeslice = !apple;
    let previewAttached = false;

    /** @type {{ blob: Blob, at: number }[]} */
    const parts = [];

    function pickMimeType() {
        if (typeof MediaRecorder === 'undefined' || apple) return '';
        const candidates = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp8',
            'video/webm',
            'video/mp4',
        ];
        return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
    }

    function blobType() {
        return (mimeType || parts[0]?.blob?.type || (apple ? 'video/mp4' : 'video/webm')).split(';')[0];
    }

    function tickAvailable() {
        if (!recordingStartedAt || !shouldKeepRecording) {
            availableMs.value = 0;
            availableSec.value = 0;
            return 0;
        }
        const ms = Math.min(bufferMs, Math.max(0, Date.now() - recordingStartedAt));
        const sec = Math.floor(ms / 1000);
        // Only touch Vue when the displayed second changes — critical on iPhone.
        if (sec !== availableSec.value) {
            availableSec.value = sec;
            availableMs.value = ms;
        } else if (ms !== availableMs.value && ms >= bufferMs) {
            availableMs.value = ms;
        }
        return ms;
    }

    function startTicker() {
        stopTicker();
        availableSec.value = 0;
        availableMs.value = 0;
        tickAvailable();
        ticker = setInterval(tickAvailable, 1000);
    }

    function stopTicker() {
        if (ticker) clearInterval(ticker);
        ticker = null;
    }

    function attachPreview(stream = mediaStream) {
        const el = previewEl.value;
        if (!el || !stream) return false;
        if (el.srcObject !== stream) {
            el.srcObject = stream;
        }
        el.muted = true;
        el.playsInline = true;
        el.setAttribute('playsinline', 'true');
        el.setAttribute('webkit-playsinline', 'true');
        if (!previewAttached) {
            previewAttached = true;
            el.play().catch(() => {});
        }
        return true;
    }

    async function getMediaStream() {
        // Keep constraints minimal on iOS — heavy constraints freeze Safari.
        if (apple) {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: 'environment' },
            });
            hasAudio.value = false;
            return stream;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    frameRate: { ideal: 24, max: 30 },
                },
            });
            hasAudio.value = true;
            return stream;
        } catch {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: 'environment' } },
            });
            hasAudio.value = false;
            return stream;
        }
    }

    function pushChunk(blob) {
        if (!blob?.size) return;
        parts.push({ blob, at: Date.now() });
        const cutoff = Date.now() - bufferMs - 2000;
        while (parts.length > 2 && parts[0].at < cutoff) {
            parts.shift();
        }
        // Avoid reactive writes on every chunk on iOS (there usually are none until stop).
        if (!apple) {
            bytesBuffered.value = parts.reduce((sum, part) => sum + (part.blob?.size || 0), 0);
        }
    }

    function startRecorder() {
        if (!mediaStream || !shouldKeepRecording) return;

        try {
            if (apple) {
                mediaRecorder = new MediaRecorder(mediaStream);
            } else {
                const opts = {
                    videoBitsPerSecond: 1_200_000,
                };
                if (mimeType) opts.mimeType = mimeType;
                if (hasAudio.value) opts.audioBitsPerSecond = 96_000;
                try {
                    mediaRecorder = new MediaRecorder(mediaStream, opts);
                } catch {
                    mediaRecorder = new MediaRecorder(mediaStream);
                }
            }
        } catch (err) {
            error.value = 'MediaRecorder indisponível neste aparelho.';
            throw err;
        }

        mediaRecorder.ondataavailable = (event) => {
            pushChunk(event.data);
        };
        mediaRecorder.onerror = () => {
            error.value = 'Erro na gravação.';
        };

        if (usesTimeslice) mediaRecorder.start(TIMESLICE_MS);
        else mediaRecorder.start();
    }

    async function start() {
        error.value = null;
        encodingReady.value = false;
        previewAttached = false;

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

            // Show UI first so <video> exists, then attach stream once.
            isRecording.value = true;
            await nextTick();
            attachPreview(mediaStream);
            startRecorder();
            startTicker();
            encodingReady.value = true;
            tickAvailable();

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

        if (usesTimeslice && typeof mediaRecorder.requestData === 'function') {
            try {
                mediaRecorder.requestData();
                await wait(150);
            } catch {
                // ignore
            }
            return;
        }

        await new Promise((resolve) => {
            const recorder = mediaRecorder;
            let settled = false;
            const finish = () => {
                if (settled) return;
                settled = true;
                resolve();
            };
            const hang = setTimeout(finish, 3000);
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
        if (shouldKeepRecording) startRecorder();
    }

    async function snapshot() {
        if (!shouldKeepRecording && !parts.length) return null;

        await flushRecorder();

        if (apple) {
            bytesBuffered.value = parts.reduce((sum, part) => sum + (part.blob?.size || 0), 0);
        }

        if (!parts.length) return null;

        const blob = new Blob(parts.map((part) => part.blob), { type: blobType() });
        if (!blob.size) return null;

        const endedAt = Date.now();
        const durationMs = Math.min(
            bufferMs,
            Math.max(1000, endedAt - (parts[0]?.at || recordingStartedAt || endedAt)),
        );

        return {
            parts: [{
                blob,
                startedAt: endedAt - durationMs,
                endedAt,
                durationMs,
            }],
            durationSeconds: Math.round(durationMs / 1000),
        };
    }

    function hasBuffer() {
        return availableSec.value >= minClipSeconds || parts.length > 0;
    }

    function nextSequence() {
        sequence += 1;
        return sequence;
    }

    function stop() {
        shouldKeepRecording = false;
        encodingReady.value = false;
        stopTicker();

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
        availableSec.value = 0;
        previewAttached = false;
        mediaStream?.getTracks?.().forEach((track) => track.stop());
        mediaStream = null;
        if (previewEl.value) previewEl.value.srcObject = null;
        isRecording.value = false;
        usesTimeslice = !apple;
    }

    function getAvailableMsSync() {
        if (!recordingStartedAt || !shouldKeepRecording) return 0;
        return Math.min(bufferMs, Math.max(0, Date.now() - recordingStartedAt));
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
        availableSec,
        bytesBuffered,
        attachPreview,
        getAvailableMs,
        getAvailableMsSync,
        bufferSeconds,
        minClipSeconds,
    };
}
