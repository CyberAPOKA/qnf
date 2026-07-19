<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useRecBuffer } from '@/composables/useRecBuffer';
import { useRecSession } from '@/composables/useRecSession';

const CAMERA_ANGLES_B = [
    { tag: 'B1', label: 'Lateral B · Esquerda' },
    { tag: 'B2', label: 'Lateral B · Direita' },
];

const CAMERA_ANGLES_A = [
    { tag: 'A1', label: 'Lateral A · Esquerda' },
    { tag: 'A2', label: 'Lateral A · Direita' },
];

const CAMERA_ANGLES = [...CAMERA_ANGLES_B, ...CAMERA_ANGLES_A];

const props = defineProps({
    game: Object,
    recorders: Array,
    recent_saves: Array,
    buffer_seconds: Number,
    current_user_id: Number,
    current_user_name: String,
});

const {
    isRecording,
    isSupported,
    error: bufferError,
    previewEl,
    start: startBuffer,
    stop: stopBuffer,
    snapshot,
    hasBuffer,
    bufferSeconds,
    minClipSeconds,
} = useRecBuffer();

const isTogglingRec = ref(false);
const localError = ref(null);
const selectedAngle = ref(null);
const isFullscreen = ref(false);
const stageEl = ref(null);
const preferLandscapeHint = ref(false);

const session = useRecSession(props, {
    onSaveRequested: handleSaveRequested,
});

const {
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
} = session;

const isThisDeviceRecording = computed(() =>
    recorders.value.some((r) => r.recorder_id === recorderId),
);

const activeRecorderCount = computed(() => recorders.value.length);

const canSave = computed(() => activeRecorderCount.value > 0 && !isSaving.value);

const canStartRec = computed(() =>
    isSupported.value
    && !!selectedAngle.value
    && !isTogglingRec.value
    && !isRegistering.value,
);

const takenAngles = computed(() =>
    new Set(recorders.value.map((r) => r.camera_tag).filter(Boolean)),
);

function angleLabel(tag) {
    return CAMERA_ANGLES.find((a) => a.tag === tag)?.label || tag;
}

function angleButtonClass(tag) {
    return [
        selectedAngle.value === tag
            ? 'border-red-600 bg-red-50'
            : 'border-gray-200 bg-white hover:border-red-300',
        takenAngles.value.has(tag) && selectedAngle.value !== tag
            ? 'opacity-45'
            : '',
    ];
}

function formatTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function pendingLabel(uuid) {
    const pending = pendingSaves.value[uuid];
    if (!pending) return null;

    if (pending.status === 'failed') {
        return 'Falhou';
    }

    if (pending.status === 'uploading') {
        return 'Enviando...';
    }

    return `${pending.received}/${pending.expected} câmeras`;
}

function pendingBadgeClass(uuid) {
    const pending = pendingSaves.value[uuid];
    if (!pending) return 'bg-amber-100 text-amber-700';

    if (pending.status === 'failed') return 'bg-red-100 text-red-700';
    if (pending.status === 'done' || pending.received >= pending.expected) {
        return 'bg-emerald-100 text-emerald-700';
    }

    return 'bg-amber-100 text-amber-700';
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

function uploadFromBuffer(saveRequestUuid) {
    if (!isRecording.value) {
        return false;
    }

    if (!hasBuffer()) {
        localError.value = `Aguarde pelo menos ${minClipSeconds}s gravando antes de salvar.`;
        return false;
    }

    const blob = snapshot();
    if (!blob || blob.size === 0) {
        localError.value = `Buffer insuficiente. Aguarde pelo menos ${minClipSeconds}s e tente de novo.`;
        return false;
    }

    enqueueUpload(saveRequestUuid, blob, bufferSeconds, selectedAngle.value);
    return true;
}

async function lockLandscape() {
    preferLandscapeHint.value = window.matchMedia('(orientation: portrait)').matches;

    try {
        if (screen.orientation?.lock) {
            await screen.orientation.lock('landscape');
        }
    } catch {
        // Browser may block orientation lock outside fullscreen / without gesture support.
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
    const el = stageEl.value;
    if (!el) return;

    try {
        if (el.requestFullscreen) {
            await el.requestFullscreen();
        } else if (el.webkitRequestFullscreen) {
            await el.webkitRequestFullscreen();
        }
        isFullscreen.value = true;
        await lockLandscape();
    } catch {
        localError.value = 'Não foi possível entrar em tela cheia neste aparelho.';
    }
}

async function exitFullscreen() {
    try {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            if (document.exitFullscreen) {
                await document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                await document.webkitExitFullscreen();
            }
        }
    } catch {
        // ignore
    }

    isFullscreen.value = false;
    unlockOrientation();
}

function onFullscreenChange() {
    isFullscreen.value = !!(document.fullscreenElement || document.webkitFullscreenElement);
    if (!isFullscreen.value) {
        unlockOrientation();
    }
}

async function toggleRecMode() {
    if (isTogglingRec.value) return;

    isTogglingRec.value = true;
    localError.value = null;

    try {
        if (isThisDeviceRecording.value) {
            await exitFullscreen();
            stopBuffer();
            await unregisterRecorder();
            return;
        }

        if (!selectedAngle.value) {
            localError.value = 'Selecione o ângulo da câmera na quadra (A1, A2, B1 ou B2).';
            return;
        }

        if (takenAngles.value.has(selectedAngle.value)) {
            localError.value = `O ângulo ${selectedAngle.value} já está em uso.`;
            return;
        }

        const started = await startBuffer();
        if (!started) {
            localError.value = bufferError.value;
            return;
        }

        const registered = await registerRecorder(selectedAngle.value);
        if (!registered) {
            stopBuffer();
            localError.value = 'Não foi possível registrar esta câmera.';
            return;
        }

        await enterFullscreen();
    } finally {
        isTogglingRec.value = false;
    }
}

async function handleSave() {
    localError.value = null;
    const saveRequest = await triggerSave();

    if (!saveRequest) {
        return;
    }

    if (isRecording.value) {
        uploadFromBuffer(saveRequest.uuid);
    }
}

function handleSaveRequested(payload) {
    uploadFromBuffer(payload.saveRequestUuid);
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
    <AppLayout title="REC">
        <div class="py-4 pb-28">
            <div class="max-w-lg mx-auto px-4 space-y-5">

                <div
                    v-if="localError || bufferError || saveError"
                    class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3"
                >
                    {{ localError || bufferError || saveError }}
                </div>

                <div
                    v-if="!isSupported"
                    class="rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3"
                >
                    Seu navegador não suporta gravação. Use Chrome no Android para melhor resultado.
                </div>

                <!-- Posição / ângulo na quadra -->
                <div
                    v-if="!isThisDeviceRecording"
                    class="rounded-2xl bg-white shadow overflow-hidden"
                >
                    <div class="px-4 pt-4 pb-2">
                        <h2 class="text-lg text-center font-semibold text-gray-900 uppercase tracking-wide">
                            Ângulo da câmera
                        </h2>
                    </div>

                    <div class="px-3 pb-4 space-y-2">
                        <!-- Lateral B (topo da quadra) -->
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="angle in CAMERA_ANGLES_B"
                                :key="angle.tag"
                                type="button"
                                class="rounded-xl border-2 px-3 py-3 text-left transition active:scale-[0.98]"
                                :class="angleButtonClass(angle.tag)"
                                :disabled="isThisDeviceRecording"
                                @click="selectAngle(angle.tag)"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <span class="rounded-md bg-red-600 text-white text-xs font-bold px-2 py-0.5">
                                        {{ angle.tag }}
                                    </span>
                                    <span
                                        v-if="takenAngles.has(angle.tag)"
                                        class="text-[10px] uppercase font-semibold text-amber-600"
                                    >
                                        em uso
                                    </span>
                                </span>
                                <span class="block text-xs text-gray-600 mt-1">{{ angle.label }}</span>
                            </button>
                        </div>

                        <div class="relative rounded-xl overflow-hidden bg-[#1e4fd6]">
                            <img
                                src="/assets/rec/court_positions.png"
                                alt="Mapa de ângulos da quadra"
                                class="w-full h-auto block opacity-95"
                            />
                        </div>

                        <!-- Lateral A (base da quadra / banco) -->
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="angle in CAMERA_ANGLES_A"
                                :key="angle.tag"
                                type="button"
                                class="rounded-xl border-2 px-3 py-3 text-left transition active:scale-[0.98]"
                                :class="angleButtonClass(angle.tag)"
                                :disabled="isThisDeviceRecording"
                                @click="selectAngle(angle.tag)"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <span class="rounded-md bg-red-600 text-white text-xs font-bold px-2 py-0.5">
                                        {{ angle.tag }}
                                    </span>
                                    <span
                                        v-if="takenAngles.has(angle.tag)"
                                        class="text-[10px] uppercase font-semibold text-amber-600"
                                    >
                                        em uso
                                    </span>
                                </span>
                                <span class="block text-xs text-gray-600 mt-1">{{ angle.label }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Stage: preview + fullscreen -->
                <div
                    ref="stageEl"
                    class="rec-stage relative rounded-2xl overflow-hidden bg-black shadow-lg"
                    :class="[
                        isRecording ? 'block' : 'hidden',
                        isFullscreen ? 'rec-stage--fullscreen' : 'aspect-video',
                    ]"
                >
                    <video
                        ref="previewEl"
                        class="w-full h-full object-cover"
                        autoplay
                        muted
                        playsinline
                    />

                    <div class="absolute top-3 left-3 flex items-center gap-2">
                        <div class="flex items-center gap-2 bg-black/60 rounded-full px-3 py-1">
                            <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse" />
                            <span class="text-white text-xs font-semibold uppercase tracking-wide">REC</span>
                        </div>
                        <div
                            v-if="selectedAngle"
                            class="bg-red-600 text-white text-xs font-bold rounded-md px-2.5 py-1"
                        >
                            {{ selectedAngle }}
                        </div>
                    </div>

                    <div class="absolute top-3 right-3 flex gap-2">
                        <button
                            v-if="!isFullscreen"
                            type="button"
                            class="bg-black/60 text-white text-xs font-semibold rounded-full px-3 py-1.5"
                            @click="enterFullscreen"
                        >
                            <i class="fa-solid fa-expand mr-1" />
                            Tela cheia
                        </button>
                        <button
                            v-else
                            type="button"
                            class="bg-black/60 text-white text-xs font-semibold rounded-full px-3 py-1.5"
                            @click="exitFullscreen"
                        >
                            <i class="fa-solid fa-compress mr-1" />
                            Sair
                        </button>
                    </div>

                    <div
                        v-if="preferLandscapeHint && isFullscreen"
                        class="absolute inset-x-0 bottom-4 flex justify-center pointer-events-none"
                    >
                        <div class="bg-black/70 text-white text-xs rounded-full px-4 py-2">
                            <i class="fa-solid fa-mobile-screen-button mr-1 rotate-90" />
                            Gire o celular na horizontal
                        </div>
                    </div>

                    <!-- Floating actions in fullscreen -->
                    <div
                        v-if="isFullscreen"
                        class="absolute inset-x-0 bottom-6 flex justify-center gap-3 px-4"
                    >
                        <button
                            type="button"
                            class="rounded-full bg-emerald-600 text-white font-bold uppercase text-sm px-6 py-3 shadow-lg disabled:opacity-50"
                            :disabled="!canSave"
                            @click="handleSave"
                        >
                            SAVE REC
                        </button>
                        <button
                            type="button"
                            class="rounded-full bg-gray-800 text-white font-bold uppercase text-sm px-6 py-3 shadow-lg"
                            :disabled="isTogglingRec"
                            @click="toggleRecMode"
                        >
                            Parar
                        </button>
                    </div>
                </div>

                <div
                    v-if="isRecording && !isFullscreen"
                    class="rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 text-sm text-slate-700"
                >
                    <p>
                        <span class="font-semibold">Ângulo {{ selectedAngle }}</span>
                        · {{ angleLabel(selectedAngle) }}
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Prefira gravar com o celular na horizontal. Use “Tela cheia” para preencher a tela.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-3">
                    <button
                        type="button"
                        class="w-full rounded-2xl py-5 text-lg font-bold uppercase tracking-wider transition active:scale-[0.98] disabled:opacity-50"
                        :class="isThisDeviceRecording
                            ? 'bg-gray-800 text-white'
                            : 'bg-red-600 text-white shadow-lg shadow-red-200'"
                        :disabled="isThisDeviceRecording ? (isTogglingRec || isRegistering) : !canStartRec"
                        @click="toggleRecMode"
                    >
                        <i class="fa-solid fa-circle-dot mr-2" />
                        {{ isThisDeviceRecording ? 'Parar REC' : 'REC MODE' }}
                    </button>

                    <button
                        type="button"
                        class="w-full rounded-2xl py-5 text-lg font-bold uppercase tracking-wider bg-emerald-600 text-white shadow-lg shadow-emerald-200 transition active:scale-[0.98] disabled:opacity-50"
                        :disabled="!canSave"
                        @click="handleSave"
                    >
                        <i class="fa-solid fa-floppy-disk mr-2" />
                        {{ isSaving ? 'Salvando...' : 'SAVE REC' }}
                    </button>
                </div>

                <div class="rounded-2xl bg-white shadow p-4">
                    <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                        Gravando agora
                    </h2>

                    <ul v-if="recorders.length" class="space-y-2">
                        <li
                            v-for="recorder in recorders"
                            :key="recorder.recorder_id"
                            class="flex items-center justify-between text-sm"
                        >
                            <span class="flex items-center gap-2 min-w-0">
                                <span class="w-2 h-2 rounded-full bg-red-500 shrink-0" />
                                <span
                                    v-if="recorder.camera_tag"
                                    class="rounded bg-red-600 text-white text-[10px] font-bold px-1.5 py-0.5"
                                >
                                    {{ recorder.camera_tag }}
                                </span>
                                <span class="truncate">{{ recorder.user_name }}</span>
                                <span
                                    v-if="recorder.recorder_id === recorderId"
                                    class="text-xs text-gray-400 shrink-0"
                                >(você)</span>
                            </span>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-gray-500">Nenhuma câmera gravando.</p>
                </div>

                <div class="space-y-4">
                    <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide px-1">
                        Clips salvos
                    </h2>

                    <p v-if="!recentSaves.length" class="text-sm text-gray-500 px-1">
                        Nenhum clip salvo ainda.
                    </p>

                    <div
                        v-for="save in recentSaves"
                        :key="save.uuid"
                        class="rounded-2xl bg-white shadow overflow-hidden"
                    >
                        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">
                                    {{ formatTime(save.triggered_at) }}
                                </p>
                                <p v-if="save.triggered_by" class="text-xs text-gray-500">
                                    por {{ save.triggered_by }}
                                </p>
                            </div>
                            <span
                                v-if="pendingLabel(save.uuid)"
                                class="text-xs font-medium px-2 py-1 rounded-full"
                                :class="pendingBadgeClass(save.uuid)"
                            >
                                {{ pendingLabel(save.uuid) }}
                            </span>
                        </div>

                        <div v-if="save.clips?.length" class="p-3 space-y-3">
                            <div
                                v-for="clip in save.clips"
                                :key="clip.id || clip.recorder_id"
                                class="space-y-1"
                            >
                                <p class="text-xs font-medium text-gray-600 flex items-center gap-2">
                                    <span
                                        v-if="clip.camera_tag"
                                        class="rounded bg-red-600 text-white text-[10px] font-bold px-1.5 py-0.5"
                                    >
                                        {{ clip.camera_tag }}
                                    </span>
                                    {{ clip.user_name }}
                                </p>
                                <video
                                    :src="clip.url"
                                    controls
                                    playsinline
                                    preload="metadata"
                                    class="w-full rounded-xl bg-black"
                                />
                            </div>
                        </div>

                        <div
                            v-else-if="pendingSaves[save.uuid]?.status === 'failed'"
                            class="px-4 py-6 text-center text-sm text-red-600"
                        >
                            {{ pendingSaves[save.uuid]?.error || 'Falha ao salvar o clip.' }}
                        </div>

                        <div v-else class="px-4 py-6 text-center text-sm text-gray-500">
                            <i class="fa-solid fa-spinner fa-spin mr-1" />
                            {{ pendingSaves[save.uuid]?.status === 'uploading' ? 'Enviando...' : 'Aguardando câmeras...' }}
                        </div>
                    </div>
                </div>

                <Link
                    :href="route('dashboard')"
                    class="block text-center text-sm text-indigo-600 font-medium py-2"
                >
                    Voltar ao Dashboard
                </Link>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.rec-stage--fullscreen {
    position: fixed;
    inset: 0;
    z-index: 80;
    width: 100vw;
    height: 100vh;
    height: 100dvh;
    border-radius: 0;
    aspect-ratio: auto;
    background: #000;
}

.rec-stage--fullscreen video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

@media (orientation: landscape) {
    .rec-stage:not(.rec-stage--fullscreen) {
        aspect-ratio: 16 / 9;
    }
}
</style>
