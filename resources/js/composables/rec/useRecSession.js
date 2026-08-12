import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import { useRecConfig } from './recConfig';
import { useRecHealth } from './useRecHealth';
import { useRecSegmentStore } from './useRecSegmentStore';

function makeUuid() {
    return globalThis.crypto?.randomUUID?.()
        || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function wait(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

export function useRecSession(props, options = {}) {
    const config = useRecConfig(props.rec_config);
    const store = options.store || useRecSegmentStore();
    const capture = options.capture;
    const uploadQueue = options.uploadQueue;
    const session = ref(null);
    const sessions = ref([...(props.recorders || [])]);
    const recorders = sessions;
    const recentSaves = ref([...(props.recent_saves || [])]);
    const pendingSaves = ref({});
    const isSaving = ref(false);
    const saveError = ref(null);
    const isRegistering = ref(false);
    const sessionExpired = ref(false);
    const heartbeatFailures = ref(0);
    const availableMs = ref(0);
    const saveCooldownRemaining = ref(0);
    const scopeCooldowns = ref({ left: 0, right: 0, all: 0 });
    const scopeDeadlines = { left: 0, right: 0, all: 0 };
    let scopeCooldownTimer = null;
    const cooldownSaveUuids = new Set();

    function scopesLockedBy(captureScope) {
        if (captureScope === 'left') return ['left'];
        if (captureScope === 'right') return ['right'];
        return ['all', 'left', 'right'];
    }

    function scopesBlocking(captureScope) {
        if (captureScope === 'left') return ['left', 'all'];
        if (captureScope === 'right') return ['right', 'all'];
        return ['left', 'right', 'all'];
    }

    function syncScopeCooldowns() {
        const now = Date.now();
        scopeCooldowns.value = {
            left: Math.max(0, Math.ceil((scopeDeadlines.left - now) / 1000)),
            right: Math.max(0, Math.ceil((scopeDeadlines.right - now) / 1000)),
            all: Math.max(0, Math.ceil((scopeDeadlines.all - now) / 1000)),
        };
        saveCooldownRemaining.value = Math.max(
            scopeCooldowns.value.left,
            scopeCooldowns.value.right,
            scopeCooldowns.value.all,
        );
    }

    function isScopeCoolingDown(captureScope = 'all') {
        syncScopeCooldowns();
        return scopesBlocking(captureScope).some((scope) => (scopeCooldowns.value[scope] || 0) > 0);
    }

    function startScopeCooldown(seconds = 10, saveRequestUuid = null, lockedScopes = null, captureScope = 'all') {
        if (saveRequestUuid && cooldownSaveUuids.has(saveRequestUuid)) return;
        if (saveRequestUuid) cooldownSaveUuids.add(saveRequestUuid);

        const scopes = Array.isArray(lockedScopes) && lockedScopes.length
            ? lockedScopes
            : scopesLockedBy(captureScope);
        const deadline = Date.now() + (Math.max(1, Number(seconds) || 10) * 1000);
        scopes.forEach((scope) => {
            if (scope in scopeDeadlines) {
                scopeDeadlines[scope] = Math.max(scopeDeadlines[scope] || 0, deadline);
            }
        });
        syncScopeCooldowns();

        if (scopeCooldownTimer) clearInterval(scopeCooldownTimer);
        scopeCooldownTimer = setInterval(() => {
            syncScopeCooldowns();
            if (saveCooldownRemaining.value === 0 && scopeCooldownTimer) {
                clearInterval(scopeCooldownTimer);
                scopeCooldownTimer = null;
            }
        }, 250);
    }

    const gameId = props.game.id;
    const channelName = `game.${gameId}`;
    let heartbeatTimer = null;
    let pollTimer = null;
    let echoChannel = null;
    let stopped = false;
    let lastSaveAt = 0;
    let activeSaveRequests = 0;

    function routeName(name, params = {}) {
        return window.route(name, { game: gameId, ...params });
    }

    function authHeaders(current = session.value) {
        return current ? {
            'X-REC-Session': current.uuid,
            'X-REC-Token': current.token,
        } : {};
    }

    function mergeSave(incoming) {
        if (!incoming?.uuid) return null;
        const existing = recentSaves.value.find((item) => item.uuid === incoming.uuid);
        if (!existing) {
            const created = { ...incoming, clips: [...(incoming.clips || [])] };
            recentSaves.value.unshift(created);
            return created;
        }

        const clips = [...(existing.clips || [])];
        for (const clip of incoming.clips || []) {
            const index = clips.findIndex((item) =>
                item.id === clip.id
                || (item.recorder_id && item.recorder_id === clip.recorder_id));
            if (index >= 0) clips[index] = { ...clips[index], ...clip };
            else clips.push(clip);
        }
        Object.entries(incoming).forEach(([key, value]) => {
            if (key !== 'clips' && value !== undefined) existing[key] = value;
        });
        existing.clips = clips;
        return existing;
    }

    function pendingPatch(uuid, patch) {
        pendingSaves.value[uuid] = {
            expected: patch.targets?.length || 0,
            received: 0,
            status: 'waiting',
            ...(pendingSaves.value[uuid] || {}),
            ...patch,
        };
    }

    function normalizeSave(payload) {
        const save = payload?.save_request || payload?.saveRequest || payload;
        const pendingUuid = payload?.saveRequestUuid || payload?.save_request_uuid;
        if (!save?.uuid && pendingUuid) {
            return {
                ...save,
                uuid: pendingUuid,
                capture_scope: payload.captureScope || payload.capture_scope || 'all',
                capture_from: payload.expected_from || payload.capture_from,
                capture_until: payload.expected_until || payload.capture_until,
                camera_tags: payload.cameraTags || payload.camera_tags || [payload.camera_tag].filter(Boolean),
                triggered_by: payload.triggeredByName,
                triggered_at: payload.triggeredAt || new Date().toISOString(),
                targets: payload.targets || [{
                    id: payload.id,
                    camera_tag: payload.camera_tag,
                    status: payload.status,
                }],
                clips: payload.clips || [],
            };
        }
        return save;
    }

    async function acknowledgeSave(save) {
        if (!session.value || !capture || await store.wasProcessed(`save:${save.uuid}`)) return;

        const from = save.capture_from || save.captureFrom
            || Date.parse(save.triggered_at || save.triggeredAt) - config.buffer_seconds * 1000;
        const until = save.capture_until || save.captureUntil
            || Date.parse(save.triggered_at || save.triggeredAt) + config.post_roll_seconds * 1000;
        const untilMs = typeof until === 'string' ? Date.parse(until) : Number(until);
        if (Number.isFinite(untilMs) && untilMs > Date.now()) {
            await wait(untilMs - Date.now());
        }
        const segments = await capture.listLocalSegmentsInWindow(from, until);
        await uploadQueue?.prioritizeSave(save.uuid, segments);

        const body = {
            last_sequence: segments.at(-1)?.sequence || 0,
            buffer_available_ms: await capture.getAvailableMs(),
            local_segments: segments.map((item) => ({
                uuid: item.uuid,
                sequence: item.sequence,
                started_at_ms: item.startedAt,
                ended_at_ms: item.endedAt,
                checksum: item.checksum || null,
            })),
            known_gaps: [],
            capture_state: capture.isRecording.value ? 'recording' : 'stopped',
        };

        await axios.post(routeName('games.rec.sessions.ack-save', {
            session: session.value.uuid,
            saveRequest: save.uuid,
        }), body, { headers: authHeaders() });
        await store.markProcessed(`save:${save.uuid}`, { saveRequestUuid: save.uuid });
        pendingPatch(save.uuid, {
            status: segments.length ? 'uploading' : 'waiting',
            localSegments: segments.length,
        });
    }

    async function receiveSave(rawPayload) {
        const save = normalizeSave(rawPayload);
        if (!save?.uuid) return;

        mergeSave(save);
        const targets = save.targets || rawPayload?.targets || [];
        pendingPatch(save.uuid, {
            targets,
            expected: targets.length || rawPayload?.expected_recorders || 0,
            received: save.clips?.length || 0,
            status: save.status || 'waiting',
        });

        startScopeCooldown(
            rawPayload?.cooldownSeconds
                ?? rawPayload?.cooldown_seconds
                ?? config.save_scope_cooldown_seconds
                ?? 10,
            save.uuid,
            rawPayload?.lockedScopes || rawPayload?.locked_scopes,
            save.capture_scope || rawPayload?.captureScope || 'all',
        );

        try {
            await acknowledgeSave(save);
        } catch (error) {
            pendingPatch(save.uuid, {
                status: 'failed',
                error: error?.response?.data?.message || 'Não foi possível confirmar o SAVE.',
            });
        }
        options.onSaveRequested?.(save);
    }

    async function register(cameraTag) {
        isRegistering.value = true;
        saveError.value = null;
        stopped = false;

        try {
            const { data } = await axios.post(routeName('games.rec.sessions.start'), {
                camera_tag: cameraTag,
                capabilities: {
                    mime_types: typeof MediaRecorder === 'undefined'
                        ? []
                        : ['video/mp4', 'video/webm;codecs=vp8,opus', 'video/webm;codecs=vp8', 'video/webm']
                            .filter((type) => MediaRecorder.isTypeSupported(type)),
                    width: 1280,
                    height: 720,
                    fps: 24,
                    has_audio: capture?.hasAudio?.value ?? true,
                },
                client: {
                    user_agent: navigator.userAgent,
                    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                    app_version: document.documentElement.dataset.appVersion || null,
                },
            });
            const created = {
                ...(data.session || data),
                token: data.session?.token
                    || data.session?.session_token
                    || data.token
                    || data.session_token,
                gameId,
                cameraTag: data.session?.camera_tag || cameraTag,
            };
            session.value = created;
            sessionExpired.value = false;
            sessions.value = data.sessions || data.recorders || [
                ...sessions.value.filter((item) =>
                    item.camera_tag !== created.cameraTag && item.uuid !== created.uuid),
                {
                    ...created,
                    camera_tag: created.cameraTag,
                    user_name: props.current_user_name,
                },
            ];
            startHeartbeat();
            startPolling();
            // Delay upload queue until after first heartbeat tick so IndexedDB cannot stall REC start.
            setTimeout(() => uploadQueue?.processNow(), 1_500);
            store.putSession(created).catch(() => {});
            return true;
        } catch (error) {
            const message = error?.response?.data?.message
                || (typeof error?.message === 'string' && error.message.includes('games.rec.sessions.start')
                    ? 'Rota REC ausente. Rode o deploy do frontend/Ziggy e limpe o cache de rotas.'
                    : null)
                || error?.message
                || 'Não foi possível iniciar a sessão REC.';
            saveError.value = message;
            return false;
        } finally {
            isRegistering.value = false;
        }
    }

    async function unregister() {
        stopped = true;
        stopHeartbeat();
        stopPolling();
        const current = session.value;
        if (!current) return;

        try {
            const { data } = await axios.post(routeName('games.rec.sessions.stop', {
                session: current.uuid,
            }), {}, { headers: authHeaders(current) });
            sessions.value = data.sessions || data.recorders || sessions.value;
        } catch {
            // Stop is idempotent and the local session must still be closed.
        } finally {
            session.value = null;
            await store.clearSession();
        }
    }

    async function sendHeartbeat() {
        if (!session.value || stopped) return;

        // Never await IndexedDB before the keep-alive HTTP call — iOS can hang forever there.
        availableMs.value = capture?.getAvailableMsSync?.() || 0;

        try {
            const { data } = await axios.post(routeName('games.rec.sessions.heartbeat', {
                session: session.value.uuid,
            }), {
                last_segment_sequence: 0,
                buffer_available_ms: availableMs.value,
                queue_size: uploadQueue?.pendingCount?.value || 0,
                camera_state: capture?.isRecording?.value ? 'recording' : 'stopped',
                client_sent_at_ms: Date.now(),
            }, { headers: authHeaders() });

            heartbeatFailures.value = 0;
            sessions.value = data.sessions || data.recorders || sessions.value;
            for (const pending of data.pending_saves || data.pendingSaves || []) {
                await receiveSave(pending);
            }
        } catch (error) {
            heartbeatFailures.value += 1;
            if ([403, 409].includes(error?.response?.status)) {
                sessionExpired.value = true;
                stopHeartbeat();
                stopPolling();
            }
        } finally {
            if (session.value && !stopped && !sessionExpired.value) {
                heartbeatTimer = setTimeout(sendHeartbeat, config.heartbeat_seconds * 1000);
            }
        }
    }

    function startHeartbeat() {
        stopHeartbeat();
        sendHeartbeat();
    }

    function stopHeartbeat() {
        clearTimeout(heartbeatTimer);
        heartbeatTimer = null;
    }

    async function pollPendingSaves() {
        if (!session.value || stopped || sessionExpired.value) return;
        try {
            const { data } = await axios.get(routeName('games.rec.sessions.pending-saves', {
                session: session.value.uuid,
            }), { headers: authHeaders() });
            const candidates = data.pending_saves
                || data.pending
                || data.data
                || (Array.isArray(data) ? data : []);
            for (const pending of candidates) {
                await receiveSave(pending);
            }
            await Promise.all(recentSaves.value.slice(0, 10).map(async (save) => {
                try {
                    const response = await axios.get(routeName('games.rec.save-requests.show', {
                        saveRequest: save.uuid,
                    }), { headers: authHeaders() });
                    const fresh = normalizeSave(response.data);
                    fresh.clips = [
                        ...(fresh.clips || []),
                        ...(fresh.targets || [])
                            .filter((target) => target.clip)
                            .map((target) => ({
                                ...target.clip,
                                camera_tag: target.camera_tag,
                            })),
                    ];
                    mergeSave(fresh);
                    pendingPatch(fresh.uuid, {
                        targets: fresh.targets || [],
                        expected: fresh.expected_count || fresh.targets?.length || 0,
                        received: fresh.ready_count
                            ?? fresh.targets?.filter((target) => target.clip).length
                            ?? 0,
                        status: fresh.status,
                    });
                } catch {
                    // A missed detail refresh is retried by the next polling cycle.
                }
            }));
        } catch (error) {
            if ([403, 409].includes(error?.response?.status)) {
                sessionExpired.value = true;
                stopPolling();
            }
        } finally {
            if (session.value && !stopped && !sessionExpired.value) {
                pollTimer = setTimeout(
                    pollPendingSaves,
                    config.pending_save_poll_seconds * 1000,
                );
            }
        }
    }

    function startPolling() {
        stopPolling();
        pollTimer = setTimeout(pollPendingSaves, 2_000);
    }

    function stopPolling() {
        clearTimeout(pollTimer);
        pollTimer = null;
    }

    async function triggerSave(captureScope = 'all') {
        const now = Date.now();
        if (now - lastSaveAt < config.save_debounce_milliseconds) return null;
        if (isScopeCoolingDown(captureScope)) return null;
        lastSaveAt = now;
        activeSaveRequests += 1;
        isSaving.value = true;
        saveError.value = null;
        const idempotencyKey = makeUuid();

        try {
            const { data } = await axios.post(routeName('games.rec.save-requests.store'), {
                capture_scope: captureScope,
                idempotency_key: idempotencyKey,
            }, {
                headers: {
                    ...authHeaders(),
                    'Idempotency-Key': idempotencyKey,
                },
            });
            const save = normalizeSave(data);
            save.triggered_by ||= props.current_user_name;
            mergeSave(save);
            pendingPatch(save.uuid, {
                expected: data.expected_recorders || save.targets?.length || 0,
                received: save.clips?.length || 0,
                targets: save.targets || [],
                status: save.status || 'requested',
            });
            startScopeCooldown(
                data.cooldown_seconds ?? config.save_scope_cooldown_seconds ?? 10,
                save.uuid,
                data.locked_scopes,
                captureScope,
            );
            return save;
        } catch (error) {
            if (error?.response?.status === 429) {
                startScopeCooldown(
                    error.response.data?.retry_after
                        ?? error.response.data?.cooldown_seconds
                        ?? 10,
                    null,
                    error.response.data?.locked_scopes,
                    captureScope,
                );
            }
            saveError.value = error?.response?.data?.message || 'Não foi possível salvar.';
            return null;
        } finally {
            activeSaveRequests -= 1;
            isSaving.value = activeSaveRequests > 0;
        }
    }

    function handleClipReady(payload) {
        const save = mergeSave({
            uuid: payload.saveRequestUuid || payload.save_request_uuid,
            clips: payload.clip ? [payload.clip] : [],
        });
        if (save) {
            pendingPatch(save.uuid, {
                received: save.clips.length,
                status: save.targets?.length > save.clips.length ? 'partial' : 'done',
            });
        }
    }

    function subscribe() {
        if (!window.Echo) return;
        echoChannel = window.Echo.private(channelName);
        echoChannel
            .listen('.SaveClipRequested', receiveSave)
            .listen('.ClipReady', handleClipReady)
            .listen('.ClipPreviewReady', handleClipReady)
            .listen('.RecorderJoined', (data) => {
                sessions.value = data.sessions || data.recorders || sessions.value;
            })
            .listen('.RecorderLeft', (data) => {
                sessions.value = data.sessions || data.recorders || sessions.value;
            });
    }

    const health = useRecHealth({
        online: computed(() => typeof navigator === 'undefined' || navigator.onLine),
        isRecording: capture?.isRecording,
        isSupported: capture?.isSupported,
        captureError: capture?.error,
        sessionExpired,
        availableMs,
        targetBufferMs: config.buffer_seconds * 1000,
        pendingUploads: uploadQueue?.pendingCount,
        heartbeatFailures,
    });

    onMounted(async () => {
        subscribe();
        try {
            const stored = await store.getSession();
            if (stored?.gameId === gameId && stored.uuid && stored.token) {
                session.value = stored;
                stopped = false;
                startHeartbeat();
                startPolling();
                uploadQueue?.processNow();
            }
        } catch {
            // IndexedDB restore is optional; a fresh REC MODE still works.
        }
    });

    onBeforeUnmount(() => {
        stopped = true;
        stopHeartbeat();
        stopPolling();
        if (scopeCooldownTimer) clearInterval(scopeCooldownTimer);
        if (echoChannel) window.Echo.leave(`private-${channelName}`);
    });

    return {
        register,
        unregister,
        triggerSave,
        pendingSaves,
        recentSaves,
        recorders,
        sessions,
        session,
        health,
        saveError,
        isSaving,
        isRegistering,
        sessionExpired,
        heartbeatFailures,
        saveCooldownRemaining,
        scopeCooldowns,
        isScopeCoolingDown,
        recorderId: computed(() => session.value?.uuid || null),
        registerRecorder: register,
        unregisterRecorder: unregister,
        receiveSave,
    };
}
