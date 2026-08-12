import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import { useRecConfig } from './recConfig';
import { useRecSegmentStore } from './useRecSegmentStore';

const BACKOFF_MS = [1_000, 3_000, 10_000, 30_000, 60_000, 120_000, 300_000];
const RETRYABLE_STATUS = new Set([408, 425, 429, 500, 502, 503, 504]);
const PERMANENT_STATUS = new Set([400, 403, 404, 409, 413, 415, 422]);

function makeUuid() {
    return globalThis.crypto?.randomUUID?.()
        || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function routeFor(name, gameId, sessionUuid, params = {}) {
    try {
        return window.route(name, {
            game: gameId,
            session: sessionUuid,
            ...params,
        });
    } catch {
        return null;
    }
}

function sessionPath(gameId, sessionUuid, path) {
    return `/games/${gameId}/rec/sessions/${encodeURIComponent(sessionUuid)}${path}`;
}

async function sha256(blob) {
    if (!globalThis.crypto?.subtle) return null;
    const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

export function useRecUploadQueue(options = {}) {
    const config = useRecConfig(options.config);
    const store = options.store || useRecSegmentStore();
    const jobs = ref([]);
    const isProcessing = ref(false);
    const lastError = ref(null);
    const pendingCount = computed(() =>
        jobs.value.filter((job) => !['verified', 'permanent_failed'].includes(job.status)).length);

    let running = false;
    let wakeTimer = null;

    async function currentSession() {
        return options.getSession?.() || store.getSession();
    }

    function headers(session) {
        return {
            'X-REC-Session': session.uuid,
            'X-REC-Token': session.token,
        };
    }

    async function refreshJobs() {
        jobs.value = await store.listJobs();
        return jobs.value;
    }

    async function enqueueSegment(segment, saveRequestUuid = null) {
        const existing = (await store.listJobs()).find((job) =>
            job.segmentUuid === segment.uuid
            && !['verified', 'permanent_failed'].includes(job.status));

        if (existing) {
            if (saveRequestUuid && !existing.saveRequestUuids?.includes(saveRequestUuid)) {
                await store.updateJob(existing.id, {
                    saveRequestUuids: [...(existing.saveRequestUuids || []), saveRequestUuid],
                    priority: 1,
                    nextAttemptAt: 0,
                });
            }
            await refreshJobs();
            scheduleProcess(0);
            return existing;
        }

        const job = await store.enqueueJob({
            id: makeUuid(),
            sessionUuid: segment.sessionUuid,
            segmentUuid: segment.uuid,
            sequence: segment.sequence,
            saveRequestUuids: saveRequestUuid ? [saveRequestUuid] : [],
            priority: saveRequestUuid ? 1 : 0,
        });
        await refreshJobs();
        scheduleProcess(0);
        return job;
    }

    async function prioritizeSave(saveRequestUuid, segments) {
        await Promise.all(segments.map((segment) => enqueueSegment(segment, saveRequestUuid)));
        scheduleProcess(0);
    }

    function retryable(error) {
        if (!error?.response) return true;
        if (RETRYABLE_STATUS.has(error.response.status)) return true;
        if (error.response.status === 401) return true;
        return !PERMANENT_STATUS.has(error.response.status);
    }

    function retryDelay(attempts) {
        const base = BACKOFF_MS[Math.min(Math.max(0, attempts - 1), BACKOFF_MS.length - 1)];
        return Math.round(base * (0.8 + Math.random() * 0.4));
    }

    function isVerified(data, segmentUuid, sequence = null) {
        if (
            data?.verified === true
            || data?.status === 'verified'
            || data?.segment?.verified === true
            || data?.segment?.status === 'verified'
        ) return true;
        const candidates = data?.segments || data?.verified_segments || [];
        return candidates.some((item) =>
            item === segmentUuid
            || (
                (item?.uuid === segmentUuid || (sequence != null && item?.sequence === sequence))
                && (item.verified || item.status === 'verified')
            ));
    }

    async function verifyJob(job, session) {
        const { data } = await axios.get(
            routeFor(
                'games.rec.sessions.segments.status',
                options.gameId,
                session.uuid,
            ) || sessionPath(options.gameId, session.uuid, '/segments/status'),
            {
                headers: headers(session),
                params: {
                    from_sequence: job.sequence,
                    to_sequence: job.sequence,
                },
                timeout: config.upload_request_timeout_seconds * 1000,
            },
        );
        return isVerified(data, job.segmentUuid, job.sequence);
    }

    async function uploadJob(job, segment, session) {
        const checksum = segment.checksum || await sha256(segment.blob);
        const idempotencyKey = `rec-segment:${session.uuid}:${segment.uuid}`;
        const form = new FormData();
        form.append('session_uuid', session.uuid);
        form.append('uuid', segment.uuid);
        form.append('sequence', String(segment.sequence));
        form.append('idempotency_key', idempotencyKey);
        form.append('client_started_at', new Date(segment.startedAt).toISOString());
        form.append('client_ended_at', new Date(segment.endedAt).toISOString());
        form.append('duration_ms', String(segment.durationMs));
        form.append('mime_type', segment.mimeType || segment.blob.type);
        if (checksum) form.append('checksum_sha256', checksum);
        form.append('segment', segment.blob, `${segment.sequence}-${segment.uuid}.webm`);

        const { data } = await axios.post(
            routeFor('games.rec.sessions.segments', options.gameId, session.uuid)
                || sessionPath(options.gameId, session.uuid, '/segments'),
            form,
            {
                headers: {
                    ...headers(session),
                    'Idempotency-Key': idempotencyKey,
                },
                timeout: config.upload_request_timeout_seconds * 1000,
            },
        );
        return { data, checksum };
    }

    async function markVerified(job) {
        await store.updateJob(job.id, {
            status: 'verified',
            verifiedAt: Date.now(),
            nextAttemptAt: 0,
            error: null,
        });
        // The server has explicitly verified the upload; local deletion is now safe.
        await store.deleteSegment(job.segmentUuid);
        options.onVerified?.(job);
    }

    async function processJob(job) {
        const session = await currentSession();
        if (!session?.uuid || !session?.token) return false;

        const segments = await store.getSegments(job.sessionUuid);
        const segment = segments.find((item) => item.uuid === job.segmentUuid);
        if (!segment) {
            await store.updateJob(job.id, {
                status: 'permanent_failed',
                error: 'Segmento local não encontrado.',
            });
            return true;
        }

        try {
            await store.updateJob(job.id, { status: 'uploading', error: null });
            let verified = false;
            let checksum = job.checksum;

            if (job.uploadedAt) {
                verified = await verifyJob(job, session);
            } else {
                const result = await uploadJob(job, segment, session);
                checksum = result.checksum;
                verified = isVerified(result.data, segment.uuid, segment.sequence);
                await store.updateJob(job.id, {
                    uploadedAt: Date.now(),
                    checksum,
                    status: verified ? 'verified' : 'awaiting_verification',
                });
            }

            if (verified) {
                await markVerified(job);
            } else {
                const attempts = (job.attempts || 0) + 1;
                await store.updateJob(job.id, {
                    attempts,
                    checksum,
                    status: 'awaiting_verification',
                    nextAttemptAt: Date.now() + retryDelay(attempts),
                });
            }
        } catch (uploadError) {
            if (uploadError?.response?.status === 401) {
                await options.onSessionExpired?.();
            }

            const message = uploadError?.response?.data?.message || uploadError?.message || 'Falha no upload.';
            lastError.value = message;
            if (!retryable(uploadError)) {
                await store.updateJob(job.id, {
                    status: 'permanent_failed',
                    error: message,
                });
            } else {
                const attempts = (job.attempts || 0) + 1;
                await store.updateJob(job.id, {
                    attempts,
                    status: 'queued',
                    error: message,
                    nextAttemptAt: Date.now() + retryDelay(attempts),
                });
            }
        }
        return true;
    }

    function pickNextJob() {
        const now = Date.now();
        return jobs.value
            .filter((job) =>
                !['verified', 'permanent_failed'].includes(job.status)
                && (job.nextAttemptAt || 0) <= now)
            .sort((a, b) =>
                (b.priority || 0) - (a.priority || 0)
                || (a.sequence || 0) - (b.sequence || 0))[0];
    }

    function scheduleProcess(delayMs = 0) {
        clearTimeout(wakeTimer);
        if (!running) return;
        wakeTimer = setTimeout(() => processNow(), Math.max(0, delayMs));
    }

    async function processNow() {
        if (isProcessing.value || !running || navigator.onLine === false) return;
        isProcessing.value = true;
        clearTimeout(wakeTimer);

        try {
            await refreshJobs();
            const next = pickNextJob();
            if (next) {
                await processJob(next);
            }
        } finally {
            isProcessing.value = false;
            await refreshJobs();

            const pending = jobs.value.filter((job) =>
                !['verified', 'permanent_failed'].includes(job.status));
            if (!pending.length || !running) return;

            const now = Date.now();
            const ready = pending.some((job) => (job.nextAttemptAt || 0) <= now);
            if (ready) {
                scheduleProcess(50);
                return;
            }

            const nextAt = Math.min(...pending.map((job) => job.nextAttemptAt || now));
            if (Number.isFinite(nextAt)) {
                scheduleProcess(Math.max(250, nextAt - now));
            }
        }
    }

    async function start() {
        running = true;
        try {
            await refreshJobs();
        } catch {
            // IndexedDB may be unavailable; uploads stay disabled until next start().
        }
        scheduleProcess(0);
    }

    function stop() {
        running = false;
        clearTimeout(wakeTimer);
    }

    function handleOnline() {
        scheduleProcess(0);
    }

    onMounted(() => {
        window.addEventListener('online', handleOnline);
    });
    onBeforeUnmount(() => {
        window.removeEventListener('online', handleOnline);
        stop();
    });

    return {
        jobs,
        pendingCount,
        isProcessing,
        lastError,
        start,
        stop,
        enqueueSegment,
        prioritizeSave,
        processNow,
        refreshJobs,
    };
}
