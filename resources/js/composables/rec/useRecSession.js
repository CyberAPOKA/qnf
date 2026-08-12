import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import { useRecConfig } from './recConfig';
import { useRecHealth } from './useRecHealth';

function makeUuid() {
    return globalThis.crypto?.randomUUID?.()
        || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function wait(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function isAppleMobile() {
    if (typeof navigator === 'undefined') return false;
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/**
 * REC session: start/stop, plain-interval heartbeat, SAVE + ack upload.
 * No IndexedDB and no Blob Workers on the hot path.
 */
export function useRecSession(props, options = {}) {
    const config = useRecConfig(props.rec_config);
    const capture = options.capture;
    const apple = isAppleMobile();

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
    const saveCooldownRemaining = ref(0);
    const scopeCooldowns = ref({ left: 0, right: 0, all: 0 });
    const scopeDeadlines = { left: 0, right: 0, all: 0 };
    let scopeCooldownTimer = null;
    const cooldownSaveUuids = new Set();
    const processedSaves = new Set();

    const gameId = props.game.id;
    const channelName = `game.${gameId}`;
    let heartbeatTimer = null;
    let heartbeatInFlight = false;
    let pollTimer = null;
    let echoChannel = null;
    let stopped = false;
    let lastSaveAt = 0;
    let activeSaveRequests = 0;

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

    function sessionPath(path, current = session.value) {
        if (!current?.uuid) return null;
        return `/games/${gameId}/rec/sessions/${encodeURIComponent(current.uuid)}${path}`;
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
                || (item.recorder_id && item.recorder_id === clip.recorder_id)
                || (item.camera_tag && item.camera_tag === clip.camera_tag));
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

    function saveTargetsThisCamera(save) {
        const myTag = session.value?.cameraTag || session.value?.camera_tag;
        if (!myTag) return false;
        const tags = save.camera_tags
            || save.cameraTags
            || (save.targets || []).map((target) => target.camera_tag).filter(Boolean);
        if (!tags.length) return true;
        return tags.includes(myTag);
    }

    async function uploadSnapshotParts(snapshotParts) {
        if (!session.value || !snapshotParts?.length) return [];

        const uploaded = [];
        for (const part of snapshotParts) {
            if (!part?.blob?.size) continue;
            const sequence = capture.nextSequence?.() || uploaded.length + 1;
            const uuid = capture.makeUuid?.() || makeUuid();
            const durationMs = Math.max(1, Math.min(180_000, Number(part.durationMs) || 1));
            const idempotencyKey = `rec-snap:${session.value.uuid}:${uuid}`;
            const form = new FormData();
            form.append('uuid', uuid);
            form.append('sequence', String(sequence));
            form.append('idempotency_key', idempotencyKey);
            form.append('client_started_at', new Date(part.startedAt).toISOString());
            form.append('client_ended_at', new Date(part.endedAt).toISOString());
            form.append('duration_ms', String(durationMs));
            form.append('mime_type', part.blob.type || 'video/webm');
            const ext = (part.blob.type || '').includes('mp4') ? 'mp4' : 'webm';
            form.append('segment', part.blob, `${sequence}-${uuid}.${ext}`);

            await axios.post(sessionPath('/segments'), form, {
                headers: {
                    ...authHeaders(),
                    'Idempotency-Key': idempotencyKey,
                },
                timeout: (config.upload_request_timeout_seconds || 120) * 1000,
            });
            uploaded.push({ uuid, sequence, startedAt: part.startedAt, endedAt: part.endedAt });
        }
        return uploaded;
    }

    async function acknowledgeSave(save) {
        if (!session.value || !capture) return;
        if (processedSaves.has(save.uuid)) return;
        if (!saveTargetsThisCamera(save)) return;

        const until = save.capture_until || save.captureUntil
            || Date.parse(save.triggered_at || save.triggeredAt) + config.post_roll_seconds * 1000;
        const untilMs = typeof until === 'string' ? Date.parse(until) : Number(until);
        if (Number.isFinite(untilMs) && untilMs > Date.now()) {
            await wait(Math.min(untilMs - Date.now(), config.post_roll_seconds * 1000 + 500));
        }

        pendingPatch(save.uuid, { status: 'uploading' });

        let uploaded = [];
        try {
            const snap = await capture.snapshot?.();
            if (!snap?.parts?.length) {
                pendingPatch(save.uuid, {
                    status: 'failed',
                    error: 'Buffer vazio nesta câmera. O SAVE segue para as outras.',
                });
                return;
            }
            uploaded = await uploadSnapshotParts(snap.parts);
        } catch (error) {
            pendingPatch(save.uuid, {
                status: 'failed',
                error: error?.response?.data?.message || 'Falha no upload do clip.',
            });
            throw error;
        }

        await axios.post(
            sessionPath(`/save-requests/${encodeURIComponent(save.uuid)}/ack`),
            {
                last_sequence: uploaded.at(-1)?.sequence || 0,
                buffer_available_ms: capture.getAvailableMsSync?.() ?? 0,
                local_segments: uploaded.map((item) => ({
                    uuid: item.uuid,
                    sequence: item.sequence,
                    started_at_ms: item.startedAt,
                    ended_at_ms: item.endedAt,
                    checksum: null,
                })),
                known_gaps: [],
                capture_state: capture.isRecording.value ? 'recording' : 'stopped',
            },
            { headers: authHeaders() },
        );

        processedSaves.add(save.uuid);
        pendingPatch(save.uuid, {
            status: uploaded.length ? 'done' : 'waiting',
            localSegments: uploaded.length,
        });
    }

    async function receiveSave(rawPayload) {
        const save = normalizeSave(rawPayload);
        if (!save?.uuid) return;

        mergeSave(save);
        const targets = save.targets || rawPayload?.targets || [];
        pendingPatch(save.uuid, {
            targets,
            expected: targets.length
                || rawPayload?.expected_recorders
                || rawPayload?.expectedRecorders
                || 0,
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
    }

    async function register(cameraTag) {
        isRegistering.value = true;
        saveError.value = null;
        stopped = false;

        try {
            const { data } = await axios.post(`/games/${gameId}/rec/sessions`, {
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
                },
            });

            const created = {
                ...(data.session || data),
                token: data.session?.token || data.token || data.session_token,
                gameId,
                cameraTag: data.session?.camera_tag || cameraTag,
                camera_tag: data.session?.camera_tag || cameraTag,
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

            try {
                sessionStorage.setItem(`qnf-rec-active:${gameId}`, created.cameraTag || cameraTag);
            } catch {
                // ignore
            }
            return true;
        } catch (error) {
            saveError.value = error?.response?.data?.message
                || error?.message
                || 'Não foi possível iniciar a sessão REC.';
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
            const { data } = await axios.post(
                sessionPath('/stop', current),
                {},
                { headers: authHeaders(current) },
            );
            sessions.value = data.sessions || data.recorders || sessions.value;
        } catch {
            // ignore
        } finally {
            session.value = null;
            try {
                sessionStorage.removeItem(`qnf-rec-active:${gameId}`);
            } catch {
                // ignore
            }
        }
    }

    async function sendHeartbeat() {
        if (!session.value || stopped || heartbeatInFlight) return false;
        heartbeatInFlight = true;

        try {
            const response = await fetch(sessionPath('/heartbeat'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                    ...authHeaders(),
                },
                body: JSON.stringify({
                    last_segment_sequence: 0,
                    buffer_available_ms: capture?.getAvailableMsSync?.() ?? 0,
                    queue_size: 0,
                    camera_state: capture?.isRecording?.value ? 'recording' : 'stopped',
                    client_sent_at_ms: Date.now(),
                }),
            });

            if (!response.ok) {
                if ([403, 409, 422].includes(response.status)) {
                    sessionExpired.value = true;
                    stopHeartbeat();
                    stopPolling();
                    saveError.value = 'Sessão da câmera expirou. Toque em REC MODE de novo.';
                }
                heartbeatFailures.value += 1;
                return false;
            }

            const data = await response.json().catch(() => ({}));
            heartbeatFailures.value = 0;
            sessions.value = data.sessions || data.recorders || sessions.value;
            for (const pending of data.pending_saves || data.pendingSaves || []) {
                receiveSave(pending).catch(() => {});
            }
            return true;
        } catch {
            heartbeatFailures.value += 1;
            return false;
        } finally {
            heartbeatInFlight = false;
        }
    }

    function startHeartbeat() {
        stopHeartbeat();
        const seconds = Math.max(5, Number(config.heartbeat_seconds) || 10);
        void sendHeartbeat();
        heartbeatTimer = setInterval(() => {
            if (!session.value || stopped || sessionExpired.value) {
                stopHeartbeat();
                return;
            }
            void sendHeartbeat();
        }, seconds * 1000);
    }

    function stopHeartbeat() {
        if (heartbeatTimer) clearInterval(heartbeatTimer);
        heartbeatTimer = null;
    }

    async function pollPendingSaves() {
        if (!session.value || stopped || sessionExpired.value) return;
        try {
            const { data } = await axios.get(sessionPath('/save-requests/pending'), {
                headers: authHeaders(),
            });
            const candidates = data.pending_saves || data.pending || [];
            for (const pending of candidates) {
                receiveSave(pending).catch(() => {});
            }

            await Promise.all(recentSaves.value.slice(0, 8).map(async (save) => {
                try {
                    const response = await axios.get(
                        `/games/${gameId}/rec/save-requests/${encodeURIComponent(save.uuid)}`,
                        { headers: authHeaders() },
                    );
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
                    // retry next cycle
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
                    (config.pending_save_poll_seconds || 2) * 1000,
                );
            }
        }
    }

    function startPolling() {
        stopPolling();
        pollTimer = setTimeout(pollPendingSaves, 1500);
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
            const { data } = await axios.post(`/games/${gameId}/rec/save-requests`, {
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
        if (apple) return;
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
        encodingReady: capture?.encodingReady,
        isSupported: capture?.isSupported,
        captureError: capture?.error,
        sessionExpired,
        availableMs: capture?.availableMs,
        targetBufferMs: config.buffer_seconds * 1000,
        pendingUploads: 0,
        heartbeatFailures,
    });

    onMounted(() => {
        subscribe();
    });

    onBeforeUnmount(() => {
        stopped = true;
        stopHeartbeat();
        stopPolling();
        if (scopeCooldownTimer) clearInterval(scopeCooldownTimer);
        if (echoChannel) {
            try {
                window.Echo?.leave?.(`private-${channelName}`);
            } catch {
                // ignore
            }
        }
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
