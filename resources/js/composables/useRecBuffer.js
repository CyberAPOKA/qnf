import { ref, onBeforeUnmount, nextTick } from 'vue';

const BUFFER_SECONDS = 30;
const MIN_CLIP_SECONDS = 25;
const TIMESLICE_MS = 1000;
const FLUSH_MS = 150;
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
    /** Single-flight lock shared by rotate + snapshot (SAVE). */
    let operationChain = Promise.resolve();

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
     * Run rotate/snapshot exclusively so they never interleave.
     */
    function runExclusive(task) {
        const next = operationChain.then(task, task);
        // Keep the chain alive even if a task fails.
        operationChain = next.catch(() => {});
        return next;
    }

    /**
     * Stop current MediaRecorder and return a complete WebM ending at "now".
     * Waits briefly after stop so late dataavailable chunks are included.
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
            let settled = false;

            const finish = async () => {
                if (settled) {
                    return;
                }
                settled = true;

                // Some browsers fire onstop before the last dataavailable.
                await wait(FLUSH_MS);

                const durationMs = Math.max(0, Date.now() - startedAt);
                const blob = buildBlobFromChunks();
                chunks.length = 0;
                mediaRecorder = null;
                resolve({ blob, durationMs });
            };

            recorder.onstop = () => {
                finish();
            };

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
        return runExclusive(async () => {
            if (!shouldKeepRecording || !mediaRecorder || mediaRecorder.state !== 'recording') {
                return;
            }

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
        });
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
            operationChain = Promise.resolve();

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
     * - if short/empty, fall back to previous segment (+ current when useful)
     */
    async function snapshot() {
        return runExclusive(async () => {
            stopSegmentTimer();

            try {
                const { blob, durationMs } = await finalizeCurrentSegment();

                if (shouldKeepRecording) {
                    startRecorder();
                    startSegmentTimer();
                }

                const minMs = MIN_CLIP_SECONDS * 1000;
                const currentOk = blob && blob.size > 0;

                // Current segment alone already covers the window ending at the click.
                if (currentOk && durationMs >= minMs) {
                    return {
                        blob,
                        durationSeconds: Math.round(durationMs / 1000),
                        prefixBlob: null,
                    };
                }

                // Early in a segment, or current finalize came back empty:
                // use previous complete segment (optionally + current as tail).
                if (previousSegment?.blob && previousSegment.blob.size > 0) {
                    if (currentOk) {
                        return {
                            blob,
                            durationSeconds: BUFFER_SECONDS,
                            prefixBlob: previousSegment.blob,
                        };
                    }

                    // Current empty — still return previous so SAVE does not fail after 1+ min.
                    return {
                        blob: previousSegment.blob,
                        durationSeconds: Math.round(previousSegment.durationMs / 1000) || BUFFER_SECONDS,
                        prefixBlob: null,
                    };
                }

                // First seconds of the whole REC session — not enough footage yet.
                if (currentOk) {
                    return null;
                }

                return null;
            } catch {
                if (previousSegment?.blob && previousSegment.blob.size > 0) {
                    return {
                        blob: previousSegment.blob,
                        durationSeconds: Math.round(previousSegment.durationMs / 1000) || BUFFER_SECONDS,
                        prefixBlob: null,
                    };
                }

                return null;
            }
        });
    }

    function hasBuffer() {
        const minMs = MIN_CLIP_SECONDS * 1000;

        if (currentDurationMs() >= minMs) {
            return true;
        }

        return !!(previousSegment?.blob && previousSegment.blob.size > 0 && previousSegment.durationMs >= minMs);
    }

    function stop() {
        shouldKeepRecording = false;
        stopSegmentTimer();
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
