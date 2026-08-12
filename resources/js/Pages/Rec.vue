<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import RecActiveCameras from '@/Components/Rec/RecActiveCameras.vue';
import RecCameraHealthCard from '@/Components/Rec/RecCameraHealthCard.vue';
import RecCameraPositionSelector from '@/Components/Rec/RecCameraPositionSelector.vue';
import RecCameraStage from '@/Components/Rec/RecCameraStage.vue';
import RecSaveControls from '@/Components/Rec/RecSaveControls.vue';
import RecSaveList from '@/Components/Rec/RecSaveList.vue';
import { useRecCapture } from '@/composables/rec/useRecCapture';
import { useRecSession } from '@/composables/rec/useRecSession';

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

function isAppleMobile() {
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

let recSession = null;

const capture = useRecCapture({
    config: props.rec_config,
});

recSession = useRecSession(props, {
    capture,
});

const {
    isRecording,
    isSupported,
    error: captureError,
    previewEl,
    availableMs,
    start: startCapture,
    stop: stopCapture,
    attachPreview,
    hasBuffer,
    minClipSeconds,
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
    availableMs: sessionAvailableMs,
} = recSession;

const healthLabel = computed(() => health.label.value);
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

watch(availableMs, (ms) => {
    if (sessionAvailableMs) sessionAvailableMs.value = ms;
}, { immediate: true });

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

async function setPreviewElement(element) {
    previewEl.value = element;
    if (isRecording.value) attachPreview?.();
}

async function lockLandscape() {
    preferLandscapeHint.value = window.matchMedia('(orientation: portrait)').matches;
    if (isAppleMobile()) return;
    try {
        await screen.orientation?.lock?.('landscape');
    } catch {
        // unsupported
    }
}

function unlockOrientation() {
    try {
        screen.orientation?.unlock?.();
    } catch {
        // ignore
    }
    preferLandscapeHint.value = false;
}

async function enterFullscreen() {
    isFullscreen.value = true;
    preferLandscapeHint.value = window.matchMedia('(orientation: portrait)').matches;
    if (isAppleMobile()) {
        attachPreview?.();
        return;
    }
    await lockLandscape();
    const element = stageEl.value?.$el || stageEl.value;
    try {
        const request = element?.requestFullscreen?.bind(element)
            || element?.webkitRequestFullscreen?.bind(element);
        if (request) await request();
    } catch {
        // CSS fullscreen fallback
    }
}

async function exitFullscreen() {
    try {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            await (document.exitFullscreen?.() || document.webkitExitFullscreen?.());
        }
    } catch {
        // ignore
    }
    isFullscreen.value = false;
    unlockOrientation();
}

function onFullscreenChange() {
    if (isAppleMobile()) return;
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
        if (!isSupported.value) {
            localError.value = 'Gravação não suportada neste navegador.';
            return;
        }

        // V1 flow: camera + MediaRecorder together, then register session/heartbeat.
        const started = await startCapture();
        if (!started) {
            localError.value = captureError.value || 'Não foi possível acessar a câmera.';
            return;
        }

        attachPreview?.();

        const registered = await registerRecorder(selectedAngle.value);
        if (!registered) {
            await stopCapture();
            localError.value = saveError.value || 'Não foi possível registrar esta câmera.';
            return;
        }

        if (!isAppleMobile()) {
            await enterFullscreen();
        }
    } catch (error) {
        localError.value = error?.response?.data?.message
            || error?.message
            || 'Falha ao iniciar o REC MODE.';
        try {
            await stopCapture();
        } catch {
            // ignore
        }
        try {
            await unregisterRecorder();
        } catch {
            // ignore
        }
    } finally {
        isTogglingRec.value = false;
    }
}

async function handleSave(scope = 'all') {
    localError.value = null;
    if (isThisDeviceRecording.value && !hasBuffer?.()) {
        localError.value = `Buffer insuficiente. Aguarde cerca de ${minClipSeconds || 25}s e tente de novo.`;
        return;
    }
    const save = await triggerSave(scope);
    if (!save) return;
    await receiveSave(save);
}

onMounted(() => {
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
    unlockOrientation();
});
</script>

<template>
    <div class="py-4 pb-28">
        <div class="max-w-lg mx-auto px-4 space-y-5">
            <div v-if="localError || captureError || saveError"
                class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
                {{ localError || captureError || saveError }}
            </div>
            <div v-if="!isSupported"
                class="rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3">
                Seu navegador não suporta gravação. Use Chrome no Android ou Safari atualizado.
            </div>

            <RecCameraPositionSelector v-if="!isThisDeviceRecording" :selected="selectedAngle" :taken="takenAngles"
                :disabled="isThisDeviceRecording" @select="selectAngle" />

            <RecCameraStage ref="stageEl" :recording="isRecording" :fullscreen="isFullscreen"
                :camera-tag="selectedAngle" :landscape-hint="preferLandscapeHint" :can-save-left="canSaveScope('left')"
                :can-save-all="canSaveScope('all')" :can-save-right="canSaveScope('right')" :saving="isSaving"
                :available-label="healthLabel"
                @preview="setPreviewElement" @enter-fullscreen="enterFullscreen" @exit-fullscreen="exitFullscreen"
                @save="handleSave" @stop="toggleRecMode" />

            <RecCameraHealthCard v-if="isRecording" :status="health.status.value" :label="healthLabel"
                :color-class="health.colorClass.value" :available-ms="availableMs"
                :pending-uploads="0" :has-audio="capture.hasAudio.value" />

            <RecSaveControls :recording="isThisDeviceRecording" :can-start="canStartRec" :toggling="isTogglingRec"
                :registering="isRegistering" :saving="isSaving" :cooldown="saveCooldownRemaining || 0"
                :cooldown-left="scopeCooldowns?.left || 0" :cooldown-right="scopeCooldowns?.right || 0"
                :cooldown-all="scopeCooldowns?.all || 0" :can-left="canSaveScope('left')" :can-all="canSaveScope('all')"
                :can-right="canSaveScope('right')" @toggle="toggleRecMode" @save="handleSave" />

            <RecActiveCameras :cameras="recorders" :own-id="recorderId" />
            <RecSaveList :saves="recentSaves" :pending="pendingSaves" />

            <Link :href="route('dashboard')" class="block text-center text-sm text-indigo-600 font-medium py-2">
                Voltar ao Dashboard
            </Link>
        </div>
    </div>
</template>
