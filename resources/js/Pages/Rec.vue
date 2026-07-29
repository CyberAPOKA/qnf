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
import { useRecCapture } from '@/composables/rec/useRecCapture';
import { useRecSegmentStore } from '@/composables/rec/useRecSegmentStore';
import { useRecSession } from '@/composables/rec/useRecSession';
import { useRecUploadQueue } from '@/composables/rec/useRecUploadQueue';

const props = defineProps({
    game: Object,
    recorders: Array,
    recent_saves: Array,
    buffer_seconds: Number,
    current_user_id: Number,
    current_user_name: String,
    rec_config: Object,
});

const CAPTURE_SCOPE_TAGS = {
    all: ['A1', 'A2', 'B1', 'B2'],
    left: ['A1', 'B1'],
    right: ['A2', 'B2'],
};

const selectedAngle = ref(null);
const localError = ref(null);
const isTogglingRec = ref(false);
const isFullscreen = ref(false);
const stageEl = ref(null);
const preferLandscapeHint = ref(false);
const availableMs = ref(0);

const segmentStore = useRecSegmentStore();
let recSession = null;

const uploadQueue = useRecUploadQueue({
    config: props.rec_config,
    store: segmentStore,
    gameId: props.game.id,
});

const capture = useRecCapture({
    config: props.rec_config,
    store: segmentStore,
    sessionUuid: () => recSession?.session?.value?.uuid || null,
    cameraTag: () => selectedAngle.value,
    onSegment: (segment) => uploadQueue?.enqueueSegment(segment),
});

recSession = useRecSession(props, {
    store: segmentStore,
    capture,
    uploadQueue,
});

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
    scopeCooldowns,
    isRegistering,
    recorderId,
    registerRecorder,
    unregisterRecorder,
    triggerSave,
    isScopeCoolingDown,
    health,
    receiveSave,
} = recSession;

const activeRecorderCount = computed(() => recorders.value.length);
const isThisDeviceRecording = computed(() => isRecording.value);
const takenAngles = computed(() =>
    new Set(recorders.value.map((item) => item.camera_tag).filter(Boolean)),
);
const canStartRec = computed(() =>
    isSupported.value
    && !!selectedAngle.value
    && !isTogglingRec.value
    && !isRegistering.value,
);
const canSave = computed(() => activeRecorderCount.value > 0 && !isSaving.value);

function canSaveScope(scope) {
    if (!canSave.value) return false;
    if (isScopeCoolingDown(scope)) return false;
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
    // Always apply CSS fullscreen so iOS works even when Fullscreen API rejects.
    isFullscreen.value = true;
    await lockLandscape();

    try {
        const request = element?.requestFullscreen?.bind(element)
            || element?.webkitRequestFullscreen?.bind(element);
        if (request) {
            await request();
        }
    } catch {
        // CSS fullscreen already active; native API is best-effort on iPhone.
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
    const nativeFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement);
    if (!nativeFullscreen && isFullscreen.value) {
        // Keep CSS fullscreen unless the user explicitly exited via our UI.
        return;
    }
    isFullscreen.value = nativeFullscreen;
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
        if (!isSupported.value) {
            localError.value = 'Gravação não suportada neste navegador. Use Chrome no Android ou Safari atualizado.';
            return;
        }

        // Camera first: mobile browsers require getUserMedia inside the user gesture.
        const started = await startCapture();
        if (!started) {
            localError.value = captureError.value || 'Não foi possível acessar a câmera.';
            return;
        }

        const registered = await registerRecorder(selectedAngle.value);
        if (!registered) {
            await stopCapture();
            localError.value = saveError.value || 'Não foi possível registrar esta câmera.';
            return;
        }

        await enterFullscreen();
    } catch (error) {
        localError.value = error?.response?.data?.message
            || error?.message
            || 'Falha ao iniciar o REC MODE.';
        try {
            await stopCapture();
        } catch {
            // Best-effort cleanup.
        }
        try {
            await unregisterRecorder();
        } catch {
            // Session may never have been created.
        }
    } finally {
        isTogglingRec.value = false;
    }
}

async function handleSave(scope = 'all') {
    localError.value = null;
    const save = await triggerSave(scope);
    if (!save) return;
    await receiveSave(save);
}

async function updateAvailableMs() {
    const ms = await capture.getAvailableMs();
    availableMs.value = ms;
    if (recSession?.availableMs) {
        recSession.availableMs.value = ms;
    }
}

onMounted(() => {
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);
});

const availabilityTimer = typeof window !== 'undefined'
    ? setInterval(updateAvailableMs, 2_000)
    : null;

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
                    v-if="isRecording"
                    :status="health.status.value"
                    :label="health.label.value"
                    :color-class="health.colorClass.value"
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
                    :cooldown="saveCooldownRemaining || 0"
                    :cooldown-left="scopeCooldowns?.left || 0"
                    :cooldown-right="scopeCooldowns?.right || 0"
                    :cooldown-all="scopeCooldowns?.all || 0"
                    :can-left="canSaveScope('left')"
                    :can-all="canSaveScope('all')"
                    :can-right="canSaveScope('right')"
                    @toggle="toggleRecMode"
                    @save="handleSave"
                />

                <RecActiveCameras :cameras="recorders" :own-id="recorderId" />
                <RecPendingUploads
                    :jobs="uploadQueue.jobs.value"
                    :processing="uploadQueue.isProcessing.value"
                />
                <RecSaveList :saves="recentSaves" :pending="pendingSaves" />

                <Link :href="route('dashboard')" class="block text-center text-sm text-indigo-600 font-medium py-2">
                    Voltar ao Dashboard
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
