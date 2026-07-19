import { ref, onBeforeUnmount, nextTick } from 'vue';

const BUFFER_SECONDS = 30;
const MIN_CLIP_SECONDS = 25;
const TIMESLICE_MS = 1000;
const FLUSH_MS = 120;
/** Restart MediaRecorder on this interval so each segment is a clean WebM (timestamps from 0). */
const SEGMENT_MS = 30_000;

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
    const chunks = [];
    let segmentStartedAt = 0;
    let previousSegment = null; // { blob, durationMs, endedAt }
    let segmentTimer = null;
    let shouldKeepRecording = false;
    let finalizing = false;

    function blobType() {
        return (mimeType || chunks[0]?.type || 'video/webm').split(';')[0];
    }

    function currentDurationMs() {
        if (!segmentStartedAt) {
            return 0;
        }

        return Math.max(0, Date.now() - segmentStartedAt);
    }

    function attachPreview(stream) {
        if (previewEl.value) {
            previewEl.value.srcObject = stream;
            previewEl.value.muted = true;
            previewEl.value.playsInline = true;
            previewEl.value.play().catch(() => {});
        }
    }

    function handleDataAvailable(event) {
        if (!event.data || event.data.size === 0) {
            return;
        }

        chunks.push(event.data);
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

    function buildBlobFromChunks() {
        if (!chunks.length) {
            return null;
        }

        return new Blob(chunks.slice(), { type: blobType() });
    }

    function startRecorder() {
        if (!mediaStream || !shouldKeepRecording) {
            return;
        }

        chunks.length = 0;
        segmentStartedAt = Date.now();

        mediaRecorder = new MediaRecorder(mediaStream, recorderOptions());
        mediaRecorder.ondataavailable = handleDataAvailable;
        mediaRecorder.onerror = () => {
            error.value = 'Erro na gravação.';
        };

        mediaRecorder.start(TIMESLICE_MS);
    }

    /**
     * Stop current MediaRecorder and return a complete WebM ending at "now".
     * Timestamps start at 0 — no dead gap.
     */
    function finalizeCurrentSegment() {
        return new Promise((resolve) => {
            if (!mediaRecorder || mediaRecorder.state === 'inactive') {
                resolve({
                    blob: buildBlobFromChunks(),
                    durationMs: currentDurationMs(),
                });
                return;
            }

            const recorder = mediaRecorder;
            const startedAt = segmentStartedAt;

            const finish = () => {
                const durationMs = Math.max(0, Date.now() - startedAt);
                const blob = buildBlobFromChunks();
                chunks.length = 0;
                mediaRecorder = null;
                resolve({ blob, durationMs });
            };

            recorder.onstop = finish;

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
        if (!shouldKeepRecording || finalizing || !mediaRecorder || mediaRecorder.state !== 'recording') {
            return;
        }

        finalizing = true;

        try {
            const { blob, durationMs } = await finalizeCurrentSegment();

            if (blob && blob.size > 0 && durationMs >= MIN_CLIP_SECONDS * 1000) {
                previousSegment = {
                    blob,
                    durationMs,
                    endedAt: Date.now(),
                };
            }

            if (shouldKeepRecording) {
                startRecorder();
            }
        } finally {
            finalizing = false;
        }
    }

    function startSegmentTimer() {
        stopSegmentTimer();
        segmentTimer = setInterval(() => {
            rotateSegment();
        }, SEGMENT_MS);
    }

    function stopSegmentTimer() {
        if (segmentTimer) {
            clearInterval(segmentTimer);
            segmentTimer = null;
        }
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
            previousSegment = null;
            shouldKeepRecording = true;
            finalizing = false;

            attachPreview(mediaStream);
            startRecorder();
            startSegmentTimer();
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
     * Capture ending at the click:
     * - finalize current clean WebM (ends at now)
     * - if long enough, return it
     * - if short, also return previous segment for server-side merge+trim
     */
    async function snapshot() {
        if (finalizing) {
            await wait(FLUSH_MS);
        }

        finalizing = true;
        stopSegmentTimer();

        try {
            const { blob, durationMs } = await finalizeCurrentSegment();

            if (shouldKeepRecording) {
                startRecorder();
                startSegmentTimer();
            }

            if (!blob || blob.size === 0) {
                return null;
            }

            const minMs = MIN_CLIP_SECONDS * 1000;

            // Current segment alone already covers the window ending at the click.
            if (durationMs >= minMs) {
                return {
                    blob,
                    durationSeconds: Math.round(durationMs / 1000),
                    prefixBlob: null,
                };
            }

            // Early in a segment: need previous complete segment + current (server trims to last 30s).
            if (previousSegment?.blob) {
                return {
                    blob,
                    durationSeconds: BUFFER_SECONDS,
                    prefixBlob: previousSegment.blob,
                };
            }

            // First seconds of the whole REC session — not enough footage yet.
            return null;
        } finally {
            finalizing = false;
        }
    }

    function hasBuffer() {
        const minMs = MIN_CLIP_SECONDS * 1000;

        if (currentDurationMs() >= minMs) {
            return true;
        }

        return !!(previousSegment?.blob && previousSegment.durationMs >= minMs);
    }

    function stop() {
        shouldKeepRecording = false;
        finalizing = false;
        stopSegmentTimer();

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
