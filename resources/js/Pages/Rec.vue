<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import RecActiveCameras from '@/Components/Rec/RecActiveCameras.vue';
import RecCameraHealthCard from '@/Components/Rec/RecCameraHealthCard.vue';
import RecCameraPositionSelector from '@/Components/Rec/RecCameraPositionSelector.vue';
import RecCameraStage from '@/Components/Rec/RecCameraStage.vue';
import RecPendingUploads from '@/Components/Rec/RecPendingUploads.vue';
import RecSaveControls from '@/Components/Rec/RecSaveControls.vue';
import RecSaveList from '@/Components/Rec/RecSaveList.vue';
import { useRecBuffer } from '@/composables/useRecBuffer';
import { useRecSession } from '@/composables/useRecSession';
import { useRecCapture } from '@/composables/rec/useRecCapture';
import { useRecSegmentStore } from '@/composables/rec/useRecSegmentStore';
import { useRecSessionV2 } from '@/composables/rec/useRecSessionV2';
import { useRecUploadQueue } from '@/composables/rec/useRecUploadQueue';

const props = defineProps({
    game: Object,
    recorders: Array,
    recent_saves: Array,
    buffer_seconds: Number,
    current_user_id: Number,
    current_user_name: String,
    rec_config: Object,
    rec_v2_enabled: Boolean,
});

const CAPTURE_SCOPE_TAGS = {
    all: ['A1', 'A2', 'B1', 'B2'],
    left: ['A1', 'B1'],
    right: ['A2', 'B2'],
};

const isV2 = props.rec_v2_enabled === true;
const selectedAngle = ref(null);
const localError = ref(null);
const isTogglingRec = ref(false);
const isFullscreen = ref(false);
const stageEl = ref(null);
const preferLandscapeHint = ref(false);
const availableMs = ref(0);

const segmentStore = isV2 ? useRecSegmentStore() : null;
let recSession = null;
let uploadQueue = null;

const capture = isV2
    ? useRecCapture({
        config: props.rec_config,
        store: segmentStore,
        sessionUuid: () => recSession?.session?.value?.uuid || null,
        cameraTag: () => selectedAngle.value,
        onSegment: (segment) => uploadQueue?.enqueueSegment(segment),
    })
    : useRecBuffer();

if (isV2) {
    uploadQueue = useRecUploadQueue({
        config: props.rec_config,
        store: segmentStore,
        gameId: props.game.id,
    });
    recSession = useRecSessionV2(props, {
        store: segmentStore,
        capture,
        uploadQueue,
    });
} else {
    recSession = useRecSession(props, { onSaveRequested: handleSaveRequested });
}

const {
    isRecording,
    isSupported,
    error: captureError,
    previewEl,
    start: startCapture,
    stop: stopCapture,
} = capture;

const {
    recorders,
    recentSaves,
    pendingSaves,
    isSaving,
    saveError,
    saveCooldownRemaining,
    isRegistering,
    recorderId,
    registerRecorder,
    unregisterRecorder,
    triggerSave,
} = recSession;

const activeRecorderCount = computed(() => recorders.value.length);
const isThisDeviceRecording = computed(() =>
    isV2
        ? isRecording.value
        : recorders.value.some((item) => item.recorder_id === recorderId),
);
const takenAngles = computed(() =>
    new Set(recorders.value.map((item) => item.camera_tag).filter(Boolean)),
);
const canStartRec = computed(() =>
    isSupported.value
    && !!selectedAngle.value
    && !isTogglingRec.value
    && !isRegistering.value,
);
const canSave = computed(() =>
    activeRecorderCount.value > 0
    && (isV2 || (!isSaving.value && saveCooldownRemaining.value === 0)),
);

function canSaveScope(scope) {
    if (!canSave.value) return false;
    const tags = CAPTURE_SCOPE_TAGS[scope] || CAPTURE_SCOPE_TAGS.all;
    return recorders.value.some((item) => tags.includes(item.camera_tag));
}

function selectAngle(tag) {
    if (isThisDeviceRecording.value) return;
    if (takenAngles.value.has(tag) && selectedAngle.value !== tag) {
        localError.value = `O ângulo ${tag} já está em uso por outra câmera.`;
        return;
    }
    localError.value = null;
    selectedAngle.value = tag;
}

function setPreviewElement(element) {
    previewEl.value = element;
}

async function lockLandscape() {
    preferLandscapeHint.value = window.matchMedia('(orientation: portrait)').matches;
    try {
        await screen.orientation?.lock?.('landscape');
    } catch {
        // Orientation lock is not available on every mobile browser.
    }
}

function unlockOrientation() {
    try {
        screen.orientation?.unlock?.();
    } catch {
        // Ignore unsupported orientation APIs.
    }
    preferLandscapeHint.value = false;
}

async function enterFullscreen() {
    const element = stageEl.value?.$el || stageEl.value;
    try {
        await (element?.requestFullscreen?.() || element?.webkitRequestFullscreen?.());
        isFullscreen.value = true;
        await lockLandscape();
    } catch {
        localError.value = 'Não foi possível entrar em tela cheia neste aparelho.';
    }
}

async function exitFullscreen() {
    try {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            await (document.exitFullscreen?.() || document.webkitExitFullscreen?.());
        }
    } catch {
        // Fullscreen may already have been closed by the browser.
    }
    isFullscreen.value = false;
    unlockOrientation();
}

function onFullscreenChange() {
    isFullscreen.value = !!(document.fullscreenElement || document.webkitFullscreenElement);
    if (!isFullscreen.value) unlockOrientation();
}

async function toggleRecMode() {
    if (isTogglingRec.value) return;
    isTogglingRec.value = true;
    localError.value = null;

    try {
        if (isThisDeviceRecording.value) {
            await exitFullscreen();
            await stopCapture();
            await unregisterRecorder();
            return;
        }
        if (!selectedAngle.value) {
            localError.value = 'Selecione o ângulo da câmera na quadra.';
            return;
        }
        if (takenAngles.value.has(selectedAngle.value)) {
            localError.value = `O ângulo ${selectedAngle.value} já está em uso.`;
            return;
        }

        if (isV2) {
            const registered = await registerRecorder(selectedAngle.value);
            if (!registered) {
                localError.value = saveError.value;
                return;
            }
            const started = await startCapture();
            if (!started) {
                await unregisterRecorder();
                localError.value = captureError.value;
                return;
            }
        } else {
            const started = await startCapture();
            if (!started) {
                localError.value = captureError.value;
                return;
            }
            if (!await registerRecorder(selectedAngle.value)) {
                await stopCapture();
                localError.value = 'Não foi possível registrar esta câmera.';
                return;
            }
        }
        await enterFullscreen();
    } finally {
        isTogglingRec.value = false;
    }
}

const handledSaveRequests = new Set();

async function uploadFromLegacyBuffer(saveRequestUuid, cameraTags = null) {
    if (
        Array.isArray(cameraTags)
        && cameraTags.length
        && !cameraTags.includes(selectedAngle.value)
    ) return false;
    if (!isRecording.value || handledSaveRequests.has(saveRequestUuid)) return false;
    if (!capture.hasBuffer()) {
        localError.value = `Aguarde pelo menos ${capture.minClipSeconds}s gravando antes de salvar.`;
        return false;
    }

    const snapshot = await capture.snapshot();
    if (!snapshot?.blob?.size) {
        localError.value = 'Buffer insuficiente. Aguarde alguns segundos e tente de novo.';
        return false;
    }

    try {
        recSession.enqueueUpload(
            saveRequestUuid,
            snapshot.blob,
            snapshot.durationSeconds || capture.bufferSeconds,
            selectedAngle.value,
            snapshot.prefixBlob || null,
        );
        // Mark only after enqueue succeeds so a transient snapshot/enqueue failure can retry.
        handledSaveRequests.add(saveRequestUuid);
        return true;
    } catch {
        handledSaveRequests.delete(saveRequestUuid);
        return false;
    }
}

async function handleSave(scope = 'all') {
    localError.value = null;
    const save = await triggerSave(scope);
    if (!save) return;

    if (isV2) {
        await recSession.receiveSave(save);
    } else if (isRecording.value) {
        await uploadFromLegacyBuffer(
            save.uuid,
            save.camera_tags || CAPTURE_SCOPE_TAGS[scope],
        );
    }
}

async function handleSaveRequested(payload) {
    if (!isV2) {
        await uploadFromLegacyBuffer(payload.saveRequestUuid, payload.cameraTags);
    }
}

async function updateAvailableMs() {
    if (isV2) availableMs.value = await capture.getAvailableMs();
}

onMounted(() => {
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);
});

let availabilityTimer = null;
if (isV2 && typeof window !== 'undefined') {
    availabilityTimer = setInterval(updateAvailableMs, 2_000);
}

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
    clearInterval(availabilityTimer);
    unlockOrientation();
});
</script>

<template>
    <AppLayout title="REC">
        <div class="py-4 pb-28">
            <div class="max-w-lg mx-auto px-4 space-y-5">
                <div
                    v-if="localError || captureError || saveError"
                    class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3"
                >
                    {{ localError || captureError || saveError }}
                </div>
                <div v-if="!isSupported" class="rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3">
                    Seu navegador não suporta gravação. Use Chrome no Android para melhor resultado.
                </div>

                <RecCameraPositionSelector
                    v-if="!isThisDeviceRecording"
                    :selected="selectedAngle"
                    :taken="takenAngles"
                    :disabled="isThisDeviceRecording"
                    @select="selectAngle"
                />

                <RecCameraStage
                    ref="stageEl"
                    :recording="isRecording"
                    :fullscreen="isFullscreen"
                    :camera-tag="selectedAngle"
                    :landscape-hint="preferLandscapeHint"
                    :can-save-left="canSaveScope('left')"
                    :can-save-all="canSaveScope('all')"
                    :can-save-right="canSaveScope('right')"
                    :saving="isSaving"
                    @preview="setPreviewElement"
                    @enter-fullscreen="enterFullscreen"
                    @exit-fullscreen="exitFullscreen"
                    @save="handleSave"
                    @stop="toggleRecMode"
                />

                <RecCameraHealthCard
                    v-if="isV2 && isRecording"
                    :status="recSession.health.status.value"
                    :label="recSession.health.label.value"
                    :color-class="recSession.health.colorClass.value"
                    :available-ms="availableMs"
                    :pending-uploads="uploadQueue.pendingCount.value"
                    :has-audio="capture.hasAudio.value"
                />

                <RecSaveControls
                    :recording="isThisDeviceRecording"
                    :can-start="canStartRec"
                    :toggling="isTogglingRec"
                    :registering="isRegistering"
                    :saving="isSaving"
                    :cooldown="saveCooldownRemaining"
                    :can-left="canSaveScope('left')"
                    :can-all="canSaveScope('all')"
                    :can-right="canSaveScope('right')"
                    @toggle="toggleRecMode"
                    @save="handleSave"
                />

                <RecActiveCameras
                    :cameras="recorders"
                    :own-id="recorderId"
                    :v2="isV2"
                />
                <RecPendingUploads
                    v-if="isV2"
                    :jobs="uploadQueue.jobs.value"
                    :processing="uploadQueue.isProcessing.value"
                />
                <RecSaveList :saves="recentSaves" :pending="pendingSaves" :v2="isV2" />

                <Link :href="route('dashboard')" class="block text-center text-sm text-indigo-600 font-medium py-2">
                    Voltar ao Dashboard
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
