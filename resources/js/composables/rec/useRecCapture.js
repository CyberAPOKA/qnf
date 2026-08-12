import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useRecConfig } from './recConfig';
import { isAppleMobile, recLog } from './recUtils';

const FLUSH_MS = 150;

function wait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function makeUuid() {
    return globalThis.crypto?.randomUUID?.()
        || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function pickMimeType(apple) {
    if (typeof MediaRecorder === 'undefined') return '';
    const candidates = apple
        ? ['video/mp4', 'video/mp4;codecs=avc1', 'video/mp4;codecs=h264', 'video/webm']
        : [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp8',
            'video/webm',
            'video/mp4',
        ];
    return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

function blobExtension(mime) {
    return (mime || '').includes('mp4') ? 'mp4' : 'webm';
}

/**
 * Rolling buffer via closed MediaRecorder segments on the same MediaStream.
 * Segments rotate every segment_seconds; only buffer_seconds worth are kept.
 */
export function useRecCapture(options = {}) {
    const config = useRecConfig(options.config);
    const apple = isAppleMobile();
    const bufferSeconds = Math.max(10, Number(config.buffer_seconds) || 30);
    const segmentSeconds = apple
        ? 8
        : Math.max(4, Math.min(6, Number(config.segment_seconds) || 5));
    const bufferMs = bufferSeconds * 1000;
    const segmentMs = segmentSeconds * 1000;
    const maxSegments = Math.ceil(bufferMs / segmentMs) + 1;
    const minClipSeconds = Math.max(3, Math.min(segmentSeconds, 8));
    const previewWarmupMs = apple ? 2500 : 0;
    const firstRotationMs = apple ? segmentMs + 6000 : segmentMs;

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
    const trackEnded = ref(false);
    const recorderActive = ref(false);
    const segmentCount = ref(0);

    let mediaStream = null;
    let mediaRecorder = null;
    let mimeType = '';
    let recordingStartedAt = 0;
    let currentSegmentStartedAt = 0;
    let segmentSequence = 0;
    let uploadSequence = 0;
    let clockTimer = null;
    let rotationTimer = null;
    let generation = 0;
    let shouldKeepRecording = false;
    let previewAttached = false;
    let operationChain = Promise.resolve();
    let onSegmentClosed = options.onSegmentClosed || null;
    /** @type {Blob[]} */
    let pendingChunks = [];
    /** @type {{ blob: Blob, startedAt: number, endedAt: number, durationMs: number, sequence: number }[]} */
    let segments = [];

    function setOnSegmentClosed(handler) {
        onSegmentClosed = handler;
    }

    function enqueue(task) {
        const next = operationChain.then(task, task);
        operationChain = next.catch(() => {});
        return next;
    }

    function tickClock() {
        if (!recordingStartedAt || !shouldKeepRecording) {
            availableMs.value = 0;
            availableSec.value = 0;
            return 0;
        }
        const ms = Math.min(bufferMs, Math.max(0, Date.now() - recordingStartedAt));
        const sec = Math.floor(ms / 1000);
        if (sec !== availableSec.value) {
            availableSec.value = sec;
            availableMs.value = ms;
        } else if (ms >= bufferMs && availableMs.value !== bufferMs) {
            availableMs.value = bufferMs;
        }
        return ms;
    }

    function startClock() {
        stopClock();
        availableSec.value = 0;
        availableMs.value = 0;
        tickClock();
        const tick = () => {
            if (!shouldKeepRecording) return;
            tickClock();
            clockTimer = setTimeout(tick, 1000);
        };
        clockTimer = setTimeout(tick, 1000);
    }

    function stopClock() {
        if (clockTimer) clearTimeout(clockTimer);
        clockTimer = null;
    }

    function clearRotationTimer() {
        if (rotationTimer) clearTimeout(rotationTimer);
        rotationTimer = null;
    }

    function trimSegments() {
        const cutoff = Date.now() - bufferMs - segmentMs;
        while (segments.length > maxSegments) {
            segments.shift();
        }
        while (segments.length > 1 && segments[0].endedAt < cutoff) {
            segments.shift();
        }
        segmentCount.value = segments.length;
    }

    function scheduleRotation(first = false) {
        clearRotationTimer();
        if (!shouldKeepRecording) return;
        const delay = first ? firstRotationMs : segmentMs;
        rotationTimer = setTimeout(() => {
            rotateSegment()
                .catch((err) => recLog('SEGMENT_ROTATION_FAILED', { message: err?.message }))
                .finally(() => {
                    if (shouldKeepRecording) scheduleRotation(false);
                });
        }, delay);
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

    function bindTrackListeners(stream) {
        trackEnded.value = false;
        for (const track of stream.getTracks()) {
            track.onended = () => {
                trackEnded.value = true;
                recorderActive.value = false;
                recLog('TRACK_ENDED', { kind: track.kind, state: track.readyState });
            };
        }
    }

    async function getMediaStream() {
        const controlled = {
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
                frameRate: { ideal: 24, max: 30 },
            },
        };
        const minimal = {
            audio: false,
            video: { facingMode: { ideal: 'environment' } },
        };

        if (apple) {
            try {
                const stream = await navigator.mediaDevices.getUserMedia(controlled);
                hasAudio.value = false;
                return stream;
            } catch {
                const stream = await navigator.mediaDevices.getUserMedia(minimal);
                hasAudio.value = false;
                return stream;
            }
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: controlled.video,
            });
            hasAudio.value = true;
            return stream;
        } catch {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: controlled.video,
                });
                hasAudio.value = false;
                return stream;
            } catch {
                const stream = await navigator.mediaDevices.getUserMedia(minimal);
                hasAudio.value = false;
                return stream;
            }
        }
    }

    function recorderOptions() {
        const opts = {};
        if (mimeType) opts.mimeType = mimeType;
        if (!apple) {
            opts.videoBitsPerSecond = 1_200_000;
            if (hasAudio.value) opts.audioBitsPerSecond = 96_000;
        }
        return opts;
    }

    function startNewRecorder() {
        if (!mediaStream || !shouldKeepRecording) return;

        pendingChunks = [];
        currentSegmentStartedAt = Date.now();

        try {
            const opts = recorderOptions();
            mediaRecorder = Object.keys(opts).length
                ? new MediaRecorder(mediaStream, opts)
                : new MediaRecorder(mediaStream);
        } catch (err) {
            error.value = 'MediaRecorder indisponível neste aparelho.';
            recorderActive.value = false;
            throw err;
        }

        mediaRecorder.ondataavailable = (event) => {
            if (event.data?.size) pendingChunks.push(event.data);
        };
        mediaRecorder.onerror = () => {
            error.value = 'Erro na gravação.';
            recorderActive.value = false;
            recLog('RECORDER_ERROR', { state: mediaRecorder?.state });
        };

        mediaRecorder.start();
        recorderActive.value = true;
        recLog('SEGMENT_STARTED', {
            segmentCount: segments.length,
            mime: mimeType || mediaRecorder.mimeType || '',
        });
    }

    async function finalizeCurrentRecorder() {
        if (!mediaRecorder || mediaRecorder.state === 'inactive') {
            recorderActive.value = false;
            return null;
        }

        const recorder = mediaRecorder;
        const startedAt = currentSegmentStartedAt;
        const chunks = pendingChunks;
        mediaRecorder = null;
        pendingChunks = [];
        recorderActive.value = false;

        return new Promise((resolve) => {
            let settled = false;
            const finish = async () => {
                if (settled) return;
                settled = true;
                await wait(FLUSH_MS);
                const type = mimeType || recorder.mimeType || (apple ? 'video/mp4' : 'video/webm');
                const blob = chunks.length ? new Blob(chunks, { type }) : null;
                if (!blob?.size) {
                    resolve(null);
                    return;
                }
                const endedAt = Date.now();
                resolve({
                    blob,
                    startedAt,
                    endedAt,
                    durationMs: Math.max(1, endedAt - startedAt),
                    sequence: ++segmentSequence,
                });
            };

            const hang = setTimeout(finish, 4000);
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
    }

    async function rotateSegment() {
        return enqueue(async () => {
            if (!shouldKeepRecording) return;
            const closed = await finalizeCurrentRecorder();
            if (closed?.blob?.size) {
                segments.push(closed);
                trimSegments();
                recLog('SEGMENT_FINISHED', {
                    bytes: closed.blob.size,
                    segmentCount: segments.length,
                    durationMs: closed.durationMs,
                });
                if (onSegmentClosed) {
                    try {
                        await onSegmentClosed(closed);
                    } catch {
                        // Upload failures are retried by the queue.
                    }
                }
            }
            if (shouldKeepRecording) {
                startNewRecorder();
            }
        });
    }

    async function start() {
        error.value = null;
        encodingReady.value = false;
        previewAttached = false;
        trackEnded.value = false;

        if (!isSupported.value) {
            error.value = 'Gravação não suportada neste navegador.';
            return false;
        }
        if (isRecording.value) return true;

        generation += 1;
        const myGen = generation;

        try {
            mediaStream = await getMediaStream();
            if (myGen !== generation) {
                mediaStream.getTracks().forEach((t) => t.stop());
                mediaStream = null;
                return false;
            }

            mimeType = pickMimeType(apple);
            segments = [];
            segmentSequence = 0;
            uploadSequence = 0;
            segmentCount.value = 0;
            shouldKeepRecording = true;

            bindTrackListeners(mediaStream);

            isRecording.value = true;
            await nextTick();
            attachPreview(mediaStream);

            if (previewWarmupMs > 0) {
                recLog('PREVIEW_WARMUP', { ms: previewWarmupMs });
                await wait(previewWarmupMs);
                if (myGen !== generation || !shouldKeepRecording) {
                    stop();
                    return false;
                }
            }

            recordingStartedAt = Date.now();
            startClock();
            tickClock();
            startNewRecorder();
            scheduleRotation(true);
            encodingReady.value = true;

            recLog('CAPTURE_STARTED', {
                bufferSeconds,
                segmentSeconds,
                maxSegments,
                mime: mimeType,
                apple,
            });

            return true;
        } catch (err) {
            error.value = err?.name === 'NotAllowedError'
                ? 'Permissão da câmera negada.'
                : 'Não foi possível acessar a câmera.';
            stop();
            return false;
        }
    }

    function segmentsInWindow(extra = null) {
        const now = Date.now();
        const windowStart = now - bufferMs;
        const list = segments.filter((s) => s.endedAt > windowStart && s.blob?.size);
        if (extra?.blob?.size) list.push(extra);
        list.sort((a, b) => a.startedAt - b.startedAt);
        return list;
    }

    async function snapshot() {
        return enqueue(async () => {
            if (!shouldKeepRecording && !segments.length) return null;

            const closed = await finalizeCurrentRecorder();
            if (closed?.blob?.size) {
                segments.push(closed);
                trimSegments();
                recLog('SEGMENT_FINISHED', {
                    bytes: closed.blob.size,
                    reason: 'snapshot',
                    segmentCount: segments.length,
                });
            }

            const parts = segmentsInWindow().map((s) => ({
                blob: s.blob,
                startedAt: s.startedAt,
                endedAt: s.endedAt,
                durationMs: s.durationMs,
                sequence: s.sequence,
            }));

            if (shouldKeepRecording) {
                startNewRecorder();
                scheduleRotation(false);
            }

            if (!parts.length) return null;

            const spanMs = parts.at(-1).endedAt - parts[0].startedAt;
            return {
                parts,
                durationSeconds: Math.min(bufferSeconds, Math.max(1, Math.round(spanMs / 1000))),
            };
        });
    }

    function hasBuffer() {
        return availableSec.value >= minClipSeconds || segments.length > 0 || recorderActive.value;
    }

    function nextSequence() {
        uploadSequence += 1;
        return uploadSequence;
    }

    function checkVisibility() {
        if (!isRecording.value) return { ok: true };

        const videoTrack = mediaStream?.getVideoTracks?.()[0];
        const trackLive = videoTrack?.readyState === 'live';
        const recorderOk = recorderActive.value && mediaRecorder?.state === 'recording';

        if (!trackLive) {
            trackEnded.value = true;
            recorderActive.value = false;
            recLog('VISIBILITY_CHECK', {
                visibility: document.visibilityState,
                trackState: videoTrack?.readyState,
                recorderState: mediaRecorder?.state,
            });
            return { ok: false, reason: 'track_ended' };
        }

        if (!recorderOk && shouldKeepRecording) {
            recLog('VISIBILITY_CHECK', {
                visibility: document.visibilityState,
                trackState: videoTrack?.readyState,
                recorderState: mediaRecorder?.state,
            });
            return { ok: false, reason: 'recorder_inactive' };
        }

        return { ok: true };
    }

    async function listLocalSegmentsInWindow() {
        return segmentsInWindow();
    }

    function stop() {
        generation += 1;
        shouldKeepRecording = false;
        encodingReady.value = false;
        recorderActive.value = false;
        stopClock();
        clearRotationTimer();

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
        pendingChunks = [];
        segments = [];
        segmentCount.value = 0;
        recordingStartedAt = 0;
        availableMs.value = 0;
        availableSec.value = 0;
        previewAttached = false;
        mediaStream?.getTracks?.().forEach((track) => {
            track.onended = null;
            track.stop();
        });
        mediaStream = null;
        if (previewEl.value) previewEl.value.srcObject = null;
        isRecording.value = false;

        recLog('STOPPED', {});
    }

    function getAvailableMsSync() {
        if (!recordingStartedAt || !shouldKeepRecording) return 0;
        return Math.min(bufferMs, Math.max(0, Date.now() - recordingStartedAt));
    }

    async function getAvailableMs() {
        return getAvailableMsSync();
    }

    function getLastSegmentSequence() {
        return segmentSequence;
    }

    onBeforeUnmount(() => stop());

    return {
        start,
        startWithEncoding: start,
        startEncoding: async () => isRecording.value,
        stop,
        snapshot,
        hasBuffer,
        checkVisibility,
        listLocalSegmentsInWindow,
        setOnSegmentClosed,
        nextSequence,
        makeUuid,
        blobExtension: () => blobExtension(mimeType),
        isRecording,
        encodingReady,
        isSupported,
        error,
        previewEl,
        hasAudio,
        availableMs,
        availableSec,
        trackEnded,
        recorderActive,
        segmentCount,
        attachPreview,
        getAvailableMs,
        getAvailableMsSync,
        getLastSegmentSequence,
        bufferSeconds,
        minClipSeconds,
        segmentSeconds,
    };
}
