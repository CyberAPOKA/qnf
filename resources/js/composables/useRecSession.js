import { ref, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';

const HEARTBEAT_MS = 12_000;
const MAX_UPLOAD_RETRIES = 2;
const RECORDER_STORAGE_KEY = 'qnf_recorder_id';

function getOrCreateRecorderId() {
    let id = sessionStorage.getItem(RECORDER_STORAGE_KEY);

    if (!id) {
        id = crypto.randomUUID();
        sessionStorage.setItem(RECORDER_STORAGE_KEY, id);
    }

    return id;
}

function recLog(level, message, context = {}) {
    const payload = { ...context, at: new Date().toISOString() };
    if (level === 'error') {
        console.error(`[REC] ${message}`, payload);
        return;
    }
    if (level === 'warn') {
        console.warn(`[REC] ${message}`, payload);
        return;
    }
    console.info(`[REC] ${message}`, payload);
}

export function useRecSession(props, { onSaveRequested, onClipReady } = {}) {
    const recorders = ref([...(props.recorders || [])]);
    const recentSaves = ref([...(props.recent_saves || [])]);
    const pendingSaves = ref({});
    const isSaving = ref(false);
    const saveError = ref(null);
    const isRegistering = ref(false);

    const gameId = props.game.id;
    const channelName = `game.${gameId}`;
    const recorderId = getOrCreateRecorderId();

    let heartbeatTimer = null;
    let echoChannel = null;
    const uploadQueue = [];
    let isProcessingUpload = false;
    const uploadedKeys = new Set();
    const uploadingKeys = new Set();
    const waitTimers = new Map();

    function uploadKey(saveRequestUuid) {
        return `${saveRequestUuid}:${recorderId}`;
    }

    function clearWaitTimer(uuid) {
        const timer = waitTimers.get(uuid);
        if (timer) {
            clearTimeout(timer);
            waitTimers.delete(uuid);
        }
    }

    function armWaitTimeout(uuid) {
        clearWaitTimer(uuid);

        waitTimers.set(uuid, setTimeout(() => {
            const pending = pendingSaves.value[uuid];
            if (!pending) return;

            if (pending.status === 'waiting' || pending.status === 'uploading' || pending.status === 'partial') {
                if ((pending.received || 0) >= (pending.expected || 1)) {
                    markPendingStatus(uuid, { status: 'done' });
                    return;
                }

                markPendingStatus(uuid, {
                    status: pending.received > 0 ? 'partial' : 'failed',
                    error: pending.received > 0
                        ? null
                        : 'Nenhuma câmera enviou o clip a tempo. Tente novamente.',
                });

                if (!pending.received) {
                    saveError.value = 'Nenhuma câmera enviou o clip a tempo. Tente novamente.';
                }

                recLog('warn', 'save wait timeout', { uuid, received: pending.received });
            }
        }, 45_000));
    }

    function routeName(name, params = {}) {
        return window.route(name, { game: gameId, ...params });
    }

    function markPendingStatus(uuid, patch) {
        const current = pendingSaves.value[uuid] || { expected: 1, received: 0 };
        pendingSaves.value[uuid] = { ...current, ...patch };
    }

    function upsertSave(saveRequest, expectedRecorders = null) {
        const existing = recentSaves.value.find((s) => s.uuid === saveRequest.uuid);

        if (existing) {
            Object.assign(existing, saveRequest);

            if (expectedRecorders != null) {
                markPendingStatus(saveRequest.uuid, {
                    expected: expectedRecorders,
                    received: existing.clips?.length || 0,
                    status: existing.clips?.length ? 'partial' : 'waiting',
                });
                armWaitTimeout(saveRequest.uuid);
            }

            return existing;
        }

        recentSaves.value.unshift({
            ...saveRequest,
            clips: saveRequest.clips || [],
        });

        if (expectedRecorders != null) {
            markPendingStatus(saveRequest.uuid, {
                expected: expectedRecorders,
                received: 0,
                status: 'waiting',
            });
            armWaitTimeout(saveRequest.uuid);
        }

        return recentSaves.value[0];
    }

    function addClipToSave(saveRequestUuid, clip) {
        const save = recentSaves.value.find((s) => s.uuid === saveRequestUuid);

        if (!save) {
            upsertSave({
                uuid: saveRequestUuid,
                clips: [clip],
            });

            markPendingStatus(saveRequestUuid, {
                expected: pendingSaves.value[saveRequestUuid]?.expected ?? 1,
                received: 1,
                status: 'done',
            });
            clearWaitTimer(saveRequestUuid);

            return;
        }

        const already = save.clips.some((c) => c.recorder_id === clip.recorder_id);

        if (!already) {
            save.clips.push(clip);
        }

        const expected = pendingSaves.value[saveRequestUuid]?.expected ?? 1;
        const received = save.clips.length;

        markPendingStatus(saveRequestUuid, {
            expected,
            received,
            status: received >= expected ? 'done' : 'partial',
            error: null,
        });

        if (received >= expected) {
            clearWaitTimer(saveRequestUuid);
        }
    }

    async function registerRecorder() {
        isRegistering.value = true;

        try {
            const { data } = await axios.post(routeName('games.rec.start'), {
                recorder_id: recorderId,
            });

            recorders.value = data.recorders;
            startHeartbeat();
            recLog('info', 'recorder registered', { recorderId, count: data.recorders.length });

            return true;
        } catch (err) {
            recLog('error', 'register failed', { status: err?.response?.status });
            return false;
        } finally {
            isRegistering.value = false;
        }
    }

    async function unregisterRecorder() {
        stopHeartbeat();

        try {
            const { data } = await axios.post(routeName('games.rec.stop'), {
                recorder_id: recorderId,
            });

            recorders.value = data.recorders;
            recLog('info', 'recorder stopped', { recorderId });
        } catch (err) {
            recLog('warn', 'unregister failed', { status: err?.response?.status });
        }
    }

    function startHeartbeat() {
        stopHeartbeat();

        heartbeatTimer = setInterval(async () => {
            try {
                await axios.post(routeName('games.rec.heartbeat'), {
                    recorder_id: recorderId,
                });
            } catch (err) {
                recLog('warn', 'heartbeat failed', { status: err?.response?.status });
                stopHeartbeat();
            }
        }, HEARTBEAT_MS);
    }

    function stopHeartbeat() {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    async function triggerSave() {
        if (isSaving.value) {
            return null;
        }

        isSaving.value = true;
        saveError.value = null;

        try {
            const { data } = await axios.post(routeName('games.rec.save'));

            upsertSave(data.save_request, data.expected_recorders);
            recLog('info', 'save requested', {
                uuid: data.save_request?.uuid,
                expected: data.expected_recorders,
            });

            return data.save_request;
        } catch (err) {
            saveError.value = err?.response?.data?.message || 'Não foi possível salvar.';
            recLog('error', 'save failed', {
                status: err?.response?.status,
                message: saveError.value,
            });
            return null;
        } finally {
            isSaving.value = false;
        }
    }

    function enqueueUpload(saveRequestUuid, blob, durationSeconds) {
        const key = uploadKey(saveRequestUuid);

        if (uploadedKeys.has(key) || uploadingKeys.has(key)) {
            recLog('info', 'upload skipped (already handled)', { saveRequestUuid });
            return;
        }

        if (!blob || blob.size === 0) {
            markPendingStatus(saveRequestUuid, {
                status: 'failed',
                error: 'Buffer vazio. Aguarde alguns segundos gravando e tente de novo.',
            });
            saveError.value = 'Buffer vazio. Aguarde alguns segundos gravando e tente de novo.';
            recLog('warn', 'empty blob', { saveRequestUuid });
            return;
        }

        uploadingKeys.add(key);
        markPendingStatus(saveRequestUuid, { status: 'uploading' });

        uploadQueue.push({
            saveRequestUuid,
            blob,
            durationSeconds,
            retries: 0,
            key,
        });

        recLog('info', 'upload queued', {
            saveRequestUuid,
            bytes: blob.size,
            queue: uploadQueue.length,
        });

        processUploadQueue();
    }

    async function processUploadQueue() {
        if (isProcessingUpload) {
            return;
        }

        isProcessingUpload = true;

        while (uploadQueue.length > 0) {
            const job = uploadQueue.shift();

            try {
                const formData = new FormData();
                formData.append('save_request_uuid', job.saveRequestUuid);
                formData.append('recorder_id', recorderId);
                formData.append('duration_seconds', String(job.durationSeconds));
                formData.append('video', job.blob, `clip-${Date.now()}.webm`);

                const { data } = await axios.post(routeName('games.rec.upload'), formData);

                uploadedKeys.add(job.key);
                uploadingKeys.delete(job.key);
                addClipToSave(job.saveRequestUuid, data.clip);
                onClipReady?.(data.clip, job.saveRequestUuid);
                recLog('info', 'upload ok', {
                    saveRequestUuid: job.saveRequestUuid,
                    clipId: data.clip?.id,
                });
            } catch (err) {
                const status = err?.response?.status;
                const message = err?.response?.data?.message
                    || err?.response?.data?.errors?.video?.[0]
                    || 'Falha no upload do clip.';

                if (job.retries < MAX_UPLOAD_RETRIES) {
                    job.retries += 1;
                    uploadQueue.push(job);
                    recLog('warn', 'upload retry', {
                        saveRequestUuid: job.saveRequestUuid,
                        retries: job.retries,
                        status,
                    });
                    continue;
                }

                uploadingKeys.delete(job.key);
                markPendingStatus(job.saveRequestUuid, {
                    status: 'failed',
                    error: message,
                });
                saveError.value = message;
                recLog('error', 'upload failed', {
                    saveRequestUuid: job.saveRequestUuid,
                    status,
                    message,
                });
            }
        }

        isProcessingUpload = false;
    }

    function handleSaveClipRequested(payload) {
        upsertSave({
            uuid: payload.saveRequestUuid,
            triggered_by: payload.triggeredByName,
            triggered_at: new Date().toISOString(),
            clips: [],
        }, payload.expectedRecorders);

        recLog('info', 'SaveClipRequested received', {
            uuid: payload.saveRequestUuid,
            expected: payload.expectedRecorders,
        });

        onSaveRequested?.(payload);
    }

    function handleClipReady(payload) {
        addClipToSave(payload.saveRequestUuid, payload.clip);
        onClipReady?.(payload.clip, payload.saveRequestUuid);
        recLog('info', 'ClipReady received', {
            uuid: payload.saveRequestUuid,
            clipId: payload.clip?.id,
        });
    }

    function subscribe() {
        if (!window.Echo) {
            recLog('warn', 'Echo unavailable');
            return;
        }

        echoChannel = window.Echo.private(channelName);

        echoChannel
            .listen('.SaveClipRequested', handleSaveClipRequested)
            .listen('.ClipReady', handleClipReady)
            .listen('.RecorderJoined', (data) => {
                recorders.value = data.recorders;
            })
            .listen('.RecorderLeft', (data) => {
                recorders.value = data.recorders;
            });

        recLog('info', 'subscribed', { channelName });
    }

    function unsubscribe() {
        if (echoChannel) {
            window.Echo.leave(`private-${channelName}`);
            echoChannel = null;
        }
    }

    onMounted(() => {
        subscribe();
    });

    onBeforeUnmount(() => {
        unsubscribe();
        stopHeartbeat();
        waitTimers.forEach((timer) => clearTimeout(timer));
        waitTimers.clear();
    });

    return {
        recorders,
        recentSaves,
        pendingSaves,
        isSaving,
        saveError,
        isRegistering,
        recorderId,
        registerRecorder,
        unregisterRecorder,
        triggerSave,
        enqueueUpload,
    };
}
