import { ref, onBeforeUnmount, nextTick } from 'vue';

const BUFFER_SECONDS = 30;
const MIN_CLIP_SECONDS = 25;
const TIMESLICE_MS = 1000;
/** Keep ~30s of media chunks AFTER the init header. */
const MAX_MEDIA_CHUNKS = BUFFER_SECONDS + 2;
/** Wait briefly after requestData so the latest chunk is included in the SAVE. */
const FLUSH_MS = 150;

function pickMimeType() {
    const candidates = [
        'video/webm;codecs=vp8,opus',
        'video/webm;codecs=vp8',
        'video/webm',
        'video/mp4',
    ];

    return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

function wait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

export function useRecBuffer() {
    const isRecording = ref(false);
    const isSupported = ref(
        typeof window !== 'undefined'
        && !!navigator.mediaDevices?.getUserMedia
        && typeof MediaRecorder !== 'undefined',
    );
    const error = ref(null);
    const previewEl = ref(null);

    let mediaStream = null;
    let mediaRecorder = null;
    let mimeType = '';
    let headerChunk = null;
    const mediaChunks = [];
    /** Approximate age (ms) of each media chunk, aligned with TIMESLICE. */
    const mediaChunkAges = [];
    let recordingStartedAt = 0;

    function blobType() {
        return (mimeType || headerChunk?.type || 'video/webm').split(';')[0];
    }

    function bufferedSeconds() {
        // Prefer chunk count (what we actually keep) over wall clock.
        if (mediaChunks.length > 0) {
            return Math.min(BUFFER_SECONDS, mediaChunks.length);
        }

        if (!recordingStartedAt) {
            return 0;
        }

        return Math.min(BUFFER_SECONDS, (Date.now() - recordingStartedAt) / 1000);
    }

    function attachPreview(stream) {
        if (previewEl.value) {
            previewEl.value.srcObject = stream;
            previewEl.value.muted = true;
            previewEl.value.playsInline = true;
            previewEl.value.play().catch(() => {});
        }
    }

    function trimMediaChunks() {
        while (mediaChunks.length > MAX_MEDIA_CHUNKS) {
            mediaChunks.shift();
            mediaChunkAges.shift();
        }
    }

    function handleDataAvailable(event) {
        if (!event.data || event.data.size === 0) {
            return;
        }

        // First chunk = WebM/MP4 init header. Never discard via circular trim.
        if (!headerChunk) {
            headerChunk = event.data;
            return;
        }

        mediaChunks.push(event.data);
        mediaChunkAges.push(Date.now());
        trimMediaChunks();
    }

    function recorderOptions() {
        const options = {
            videoBitsPerSecond: 1_200_000,
            audioBitsPerSecond: 96_000,
        };

        if (mimeType) {
            options.mimeType = mimeType;
        }

        return options;
    }

    function buildBlobFromCurrent() {
        if (!headerChunk || mediaChunks.length === 0) {
            return null;
        }

        // Always ends at "now": header + the most recent rolling media chunks.
        return new Blob([headerChunk, ...mediaChunks], { type: blobType() });
    }

    async function start() {
        error.value = null;

        if (!isSupported.value) {
            error.value = 'Gravação não suportada neste navegador.';
            return false;
        }

        if (isRecording.value) {
            return true;
        }

        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    aspectRatio: { ideal: 16 / 9 },
                    frameRate: { ideal: 24, max: 30 },
                },
            });

            mimeType = pickMimeType();
            headerChunk = null;
            mediaChunks.length = 0;
            mediaChunkAges.length = 0;
            recordingStartedAt = Date.now();

            mediaRecorder = new MediaRecorder(mediaStream, recorderOptions());
            mediaRecorder.ondataavailable = handleDataAvailable;
            mediaRecorder.onerror = () => {
                error.value = 'Erro na gravação.';
            };

            attachPreview(mediaStream);
            mediaRecorder.start(TIMESLICE_MS);
            isRecording.value = true;

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

    /**
     * Capture the rolling buffer ending at the click moment (not a stale segment).
     */
    async function snapshot() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            try {
                mediaRecorder.requestData();
            } catch {
                // ignore
            }

            // Let the latest timeslice land before building the blob.
            await wait(FLUSH_MS);
        }

        return buildBlobFromCurrent();
    }

    function hasBuffer() {
        return bufferedSeconds() >= MIN_CLIP_SECONDS && !!headerChunk && mediaChunks.length > 0;
    }

    function stop() {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.ondataavailable = null;
            mediaRecorder.onerror = null;
            try {
                mediaRecorder.stop();
            } catch {
                // ignore
            }
        }

        mediaRecorder = null;
        headerChunk = null;
        mediaChunks.length = 0;
        mediaChunkAges.length = 0;
        recordingStartedAt = 0;

        if (mediaStream) {
            mediaStream.getTracks().forEach((track) => track.stop());
            mediaStream = null;
        }

        if (previewEl.value) {
            previewEl.value.srcObject = null;
        }

        isRecording.value = false;
    }

    onBeforeUnmount(() => {
        stop();
    });

    return {
        isRecording,
        isSupported,
        error,
        previewEl,
        start,
        stop,
        snapshot,
        hasBuffer,
        bufferSeconds: BUFFER_SECONDS,
        minClipSeconds: MIN_CLIP_SECONDS,
    };
}
