<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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

const BUILD_STAMP = 'rec-20260812-h';
const selectedAngle = ref(null);
const localError = ref(null);
const infoMessage = ref(null);
const isTogglingRec = ref(false);
const isFullscreen = ref(false);
const stageEl = ref(null);
const preferLandscapeHint = ref(false);
const clockLabel = ref(`clock 0s | ${BUILD_STAMP}`);
const phase = ref('idle'); // idle | camera | session | encoding

function isAppleMobile() {
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function disconnectRealtime() {
    try {
        window.Echo?.disconnect?.();
    } catch {
        // ignore
    }
}

let recSession = null;
let localClockStartedAt = 0;
let localClockTimer = null;

const capture = useRecCapture({
    config: props.rec_config,
});

recSession = useRecSession(props, {
    capture,
});

const {
    isRecording,
    encodingReady,
    isSupported,
    error: captureError,
    previewEl,
    availableMs,
    start: startCapture,
    startEncoding,
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

function startLocalClock() {
    stopLocalClock();
    localClockStartedAt = Date.now();
    const tick = () => {
        const sec = Math.floor((Date.now() - localClockStartedAt) / 1000);
        const text = `clock ${sec}s | phase=${phase.value} | ${BUILD_STAMP}`;
        clockLabel.value = text;
        // Bypass Vue for hard proof that timers still run.
        const el = document.getElementById('rec-alive-clock');
        if (el) el.textContent = text;
        try {
            document.title = `REC ${sec}s`;
        } catch {
            // ignore
        }
    };
    tick();
    localClockTimer = setInterval(tick, 250);
}

function stopLocalClock() {
    clearInterval(localClockTimer);
    localClockTimer = null;
    localClockStartedAt = 0;
    clockLabel.value = `clock 0s | ${BUILD_STAMP}`;
}

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

async function enableSession() {
    localError.value = null;
    infoMessage.value = null;
    const registered = await registerRecorder(selectedAngle.value);
    if (!registered) {
        localError.value = saveError.value || 'Falha ao registrar sessão.';
        return;
    }
    phase.value = 'session';
    infoMessage.value = 'Sessão registrada. Se o clock continuar subindo, toque em Ativar gravação.';
}

async function enableEncoding() {
    localError.value = null;
    infoMessage.value = null;
    if (isAppleMobile() && phase.value === 'camera') {
        const registered = await registerRecorder(selectedAngle.value);
        if (!registered) {
            localError.value = saveError.value || 'Falha ao registrar sessão.';
            return;
        }
        phase.value = 'session';
    }
    const encoding = await startEncoding();
    if (!encoding) {
        localError.value = captureError.value || 'Falha ao iniciar a gravação de vídeo.';
        return;
    }
    phase.value = 'encoding';
    infoMessage.value = 'Gravação de buffer ativa.';
}

async function toggleRecMode() {
    if (isTogglingRec.value) return;
    isTogglingRec.value = true;
    localError.value = null;
    infoMessage.value = null;

    try {
        if (isThisDeviceRecording.value) {
            await exitFullscreen();
            await stopCapture();
            await unregisterRecorder();
            stopLocalClock();
            phase.value = 'idle';
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

        // Start independent clock FIRST (proves JS timers work even if Vue stalls).
        phase.value = 'camera';
        startLocalClock();

        const started = await startCapture();
        if (!started) {
            stopLocalClock();
            phase.value = 'idle';
            localError.value = captureError.value || 'Não foi possível acessar a câmera.';
            return;
        }

        attachPreview?.();

        if (isAppleMobile()) {
            // CRITICAL isolate: do NOT register/encode yet.
            infoMessage.value = 'iPhone: só câmera. O relógio amarelo deve subir (2s, 3s…). Se travar, diga o stamp (h).';
            return;
        }

        const registered = await registerRecorder(selectedAngle.value);
        if (!registered) {
            await stopCapture();
            stopLocalClock();
            phase.value = 'idle';
            localError.value = saveError.value || 'Não foi possível registrar esta câmera.';
            return;
        }
        phase.value = 'session';
        await enterFullscreen();
        const encoding = await startEncoding();
        if (!encoding) {
            localError.value = captureError.value || 'Falha ao iniciar a gravação.';
        } else {
            phase.value = 'encoding';
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
        stopLocalClock();
        phase.value = 'idle';
    } finally {
        isTogglingRec.value = false;
    }
}

async function handleSave(scope = 'all') {
    localError.value = null;
    if (isAppleMobile() && phase.value !== 'encoding') {
        localError.value = 'No iPhone: registre a sessão e ative a gravação antes do SAVE.';
        return;
    }
    if (isThisDeviceRecording.value && !hasBuffer?.()) {
        localError.value = `Buffer insuficiente. Aguarde cerca de ${minClipSeconds || 25}s e tente de novo.`;
        return;
    }
    const save = await triggerSave(scope);
    if (!save) return;
    await receiveSave(save);
}

onMounted(() => {
    if (isAppleMobile()) disconnectRealtime();
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
    unlockOrientation();
    stopLocalClock();
});
</script>

<template>
    <div class="py-4 pb-28">
        <div class="max-w-lg mx-auto px-4 space-y-5">
            <div
                id="rec-alive-clock"
                class="text-xs text-center text-gray-700 font-mono bg-yellow-100 border border-yellow-300 rounded px-2 py-2"
            >
                {{ clockLabel }}
            </div>
            <div v-if="infoMessage"
                class="rounded-lg bg-sky-50 border border-sky-200 text-sky-900 text-sm px-4 py-3">
                {{ infoMessage }}
            </div>
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

            <div v-if="isThisDeviceRecording && isAppleMobile()" class="space-y-2">
                <button
                    v-if="phase === 'camera'"
                    type="button"
                    class="w-full rounded-xl bg-slate-800 text-white font-semibold py-3"
                    @click="enableSession"
                >
                    2) Registrar sessão
                </button>
                <button
                    v-if="phase === 'camera' || phase === 'session'"
                    type="button"
                    class="w-full rounded-xl bg-indigo-600 text-white font-semibold py-3"
                    @click="enableEncoding"
                >
                    3) Ativar gravação (buffer)
                </button>
            </div>

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
