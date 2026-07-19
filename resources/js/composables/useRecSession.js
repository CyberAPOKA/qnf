import { ref, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';

const HEARTBEAT_MS = 12_000;
const RECORDER_STORAGE_KEY = 'qnf_recorder_id';

function getOrCreateRecorderId() {
    let id = sessionStorage.getItem(RECORDER_STORAGE_KEY);

    if (!id) {
        id = crypto.randomUUID();
        sessionStorage.setItem(RECORDER_STORAGE_KEY, id);
    }

    return id;
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

    function routeName(name, params = {}) {
        return window.route(name, { game: gameId, ...params });
    }

    function upsertSave(saveRequest, expectedRecorders = null) {
        const existing = recentSaves.value.find((s) => s.uuid === saveRequest.uuid);

        if (existing) {
            Object.assign(existing, saveRequest);

            if (expectedRecorders != null) {
                pendingSaves.value[saveRequest.uuid] = {
                    expected: expectedRecorders,
                    received: existing.clips?.length || 0,
                };
            }

            return existing;
        }

        recentSaves.value.unshift({
            ...saveRequest,
            clips: saveRequest.clips || [],
        });

        if (expectedRecorders != null) {
            pendingSaves.value[saveRequest.uuid] = {
                expected: expectedRecorders,
                received: 0,
            };
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

            pendingSaves.value[saveRequestUuid] = {
                expected: pendingSaves.value[saveRequestUuid]?.expected ?? 1,
                received: 1,
            };

            return;
        }

        const already = save.clips.some((c) => c.recorder_id === clip.recorder_id);

        if (!already) {
            save.clips.push(clip);
        }

        if (pendingSaves.value[saveRequestUuid]) {
            pendingSaves.value[saveRequestUuid].received = save.clips.length;
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

            return true;
        } catch {
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
        } catch {
            // ignore
        }
    }

    function startHeartbeat() {
        stopHeartbeat();

        heartbeatTimer = setInterval(async () => {
            try {
                await axios.post(routeName('games.rec.heartbeat'), {
                    recorder_id: recorderId,
                });
            } catch {
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

            return data.save_request;
        } catch (err) {
            saveError.value = err?.response?.data?.message || 'Não foi possível salvar.';
            return null;
        } finally {
            isSaving.value = false;
        }
    }

    function enqueueUpload(saveRequestUuid, blob, durationSeconds) {
        uploadQueue.push({ saveRequestUuid, blob, durationSeconds });
        processUploadQueue();
    }

    async function processUploadQueue() {
        if (isProcessingUpload || uploadQueue.length === 0) {
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

                const { data } = await axios.post(routeName('games.rec.upload'), formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });

                addClipToSave(job.saveRequestUuid, data.clip);
                onClipReady?.(data.clip, job.saveRequestUuid);
            } catch {
                // retry once
                uploadQueue.unshift(job);
                break;
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

        onSaveRequested?.(payload);
    }

    function handleClipReady(payload) {
        addClipToSave(payload.saveRequestUuid, payload.clip);
        onClipReady?.(payload.clip, payload.saveRequestUuid);
    }

    function subscribe() {
        if (!window.Echo) {
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
