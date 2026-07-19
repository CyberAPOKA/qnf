import { ref, onBeforeUnmount, nextTick } from 'vue';

const BUFFER_SECONDS = 30;
const MIN_CLIP_SECONDS = 25;
const TIMESLICE_MS = 1000;
/** Keep ~30s of media chunks AFTER the init header. */
const MAX_MEDIA_CHUNKS = BUFFER_SECONDS + 2;
/**
 * Soft-restart after a full ~30s cycle so each fallback segment is long enough.
 * Snapshot prefers a segment with at least MIN_CLIP_SECONDS.
 */
const ROTATE_MS = 30_000;

function pickMimeType() {
    const candidates = [
        'video/webm;codecs=vp8,opus',
        'video/webm;codecs=vp8',
        'video/webm',
        'video/mp4',
    ];

    return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
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
    let fallbackSegment = null;
    let fallbackDurationMs = 0;
    let cycleStartedAt = 0;
    let rotateTimer = null;
    let rotating = false;
    let shouldKeepRecording = false;

    function blobType() {
        return (mimeType || headerChunk?.type || 'video/webm').split(';')[0];
    }

    function currentDurationMs() {
        if (!cycleStartedAt) {
            return 0;
        }

        return Math.max(0, Date.now() - cycleStartedAt);
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
        }
    }

    function handleDataAvailable(event) {
        if (!event.data || event.data.size === 0) {
            return;
        }

        // First chunk of each recorder session = WebM/MP4 init header. NEVER discard it.
        if (!headerChunk) {
            headerChunk = event.data;
            return;
        }

        mediaChunks.push(event.data);
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

    function clearCurrentBuffer() {
        headerChunk = null;
        mediaChunks.length = 0;
    }

    function startRecorder() {
        if (!mediaStream || !shouldKeepRecording) {
            return;
        }

        clearCurrentBuffer();
        cycleStartedAt = Date.now();

        mediaRecorder = new MediaRecorder(mediaStream, recorderOptions());
        mediaRecorder.ondataavailable = handleDataAvailable;
        mediaRecorder.onerror = () => {
            error.value = 'Erro na gravação.';
        };
        mediaRecorder.onstop = () => {
            if (shouldKeepRecording && !rotating) {
                startRecorder();
            }
        };

        mediaRecorder.start(TIMESLICE_MS);
    }

    function buildBlobFromCurrent() {
        if (!headerChunk || mediaChunks.length === 0) {
            return null;
        }

        return new Blob([headerChunk, ...mediaChunks], { type: blobType() });
    }

    function stopRotateTimer() {
        if (rotateTimer) {
            clearInterval(rotateTimer);
            rotateTimer = null;
        }
    }

    function rotateRecorder() {
        if (!shouldKeepRecording || !mediaRecorder || mediaRecorder.state !== 'recording' || rotating) {
            return;
        }

        rotating = true;

        const duration = currentDurationMs();
        const current = buildBlobFromCurrent();

        // Only replace fallback when this cycle is long enough to satisfy MIN_CLIP.
        if (current && current.size > 0 && duration >= MIN_CLIP_SECONDS * 1000) {
            fallbackSegment = current;
            fallbackDurationMs = duration;
        }

        const recorder = mediaRecorder;

        recorder.onstop = () => {
            rotating = false;
            if (shouldKeepRecording) {
                startRecorder();
            }
        };

        try {
            recorder.requestData();
        } catch {
            // ignore
        }

        recorder.stop();
    }

    function startRotateTimer() {
        stopRotateTimer();
        rotateTimer = setInterval(rotateRecorder, ROTATE_MS);
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
            fallbackSegment = null;
            fallbackDurationMs = 0;
            shouldKeepRecording = true;
            rotating = false;

            attachPreview(mediaStream);
            startRecorder();
            startRotateTimer();
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

    function snapshot() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            try {
                mediaRecorder.requestData();
            } catch {
                // ignore
            }
        }

        const current = buildBlobFromCurrent();
        const currentMs = currentDurationMs();
        const minMs = MIN_CLIP_SECONDS * 1000;

        // Prefer live buffer once it has enough duration.
        if (current && current.size > 0 && currentMs >= minMs) {
            return current;
        }

        // Right after rotate (or early in a new cycle), use the previous full segment.
        if (fallbackSegment && fallbackSegment.size > 0 && fallbackDurationMs >= minMs) {
            return fallbackSegment;
        }

        // Still warming up: do not return a short clip.
        return null;
    }

    function hasBuffer() {
        const minMs = MIN_CLIP_SECONDS * 1000;

        if (fallbackSegment && fallbackSegment.size > 0 && fallbackDurationMs >= minMs) {
            return true;
        }

        return !!buildBlobFromCurrent() && currentDurationMs() >= minMs;
    }

    function stop() {
        shouldKeepRecording = false;
        rotating = false;
        stopRotateTimer();

        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.onstop = null;
            try {
                mediaRecorder.stop();
            } catch {
                // ignore
            }
        }

        mediaRecorder = null;
        clearCurrentBuffer();
        fallbackSegment = null;
        fallbackDurationMs = 0;
        cycleStartedAt = 0;

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
