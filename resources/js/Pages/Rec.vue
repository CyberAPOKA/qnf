<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useRecBuffer } from '@/composables/useRecBuffer';
import { useRecSession } from '@/composables/useRecSession';

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
    bufferSeconds,
} = useRecBuffer();

const isTogglingRec = ref(false);
const localError = ref(null);

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
    return `${pending.received}/${pending.expected} câmeras`;
}

async function toggleRecMode() {
    if (isTogglingRec.value) return;

    isTogglingRec.value = true;
    localError.value = null;

    try {
        if (isThisDeviceRecording.value) {
            stopBuffer();
            await unregisterRecorder();
            return;
        }

        const started = await startBuffer();
        if (!started) {
            localError.value = bufferError.value;
            return;
        }

        const registered = await registerRecorder();
        if (!registered) {
            stopBuffer();
            localError.value = 'Não foi possível registrar esta câmera.';
        }
    } finally {
        isTogglingRec.value = false;
    }
}

async function handleSave() {
    localError.value = null;
    const saveRequest = await triggerSave();

    if (saveRequest && isRecording.value) {
        const blob = snapshot();
        if (blob && blob.size > 0) {
            enqueueUpload(saveRequest.uuid, blob, bufferSeconds);
        }
    }
}

function handleSaveRequested(payload) {
    if (!isRecording.value) {
        return;
    }

    const blob = snapshot();
    if (!blob || blob.size === 0) {
        return;
    }

    enqueueUpload(payload.saveRequestUuid, blob, bufferSeconds);
}
</script>

<template>
    <AppLayout title="REC">
        <div class="py-4 pb-28">
            <div class="max-w-lg mx-auto px-4 space-y-5">
                <!-- Header -->
                <div class="text-center">
                    <p class="text-sm text-gray-500">Rodada {{ game.round }} · {{ game.date }}</p>
                    <h1 class="text-2xl font-bold text-gray-900 mt-1">Modo REC</h1>
                    <p class="text-sm text-gray-600 mt-1">
                        Buffer de {{ buffer_seconds }}s · {{ activeRecorderCount }} câmera(s) ativa(s)
                    </p>
                </div>

                <!-- Errors -->
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

                <!-- Camera preview (always in DOM so ref is available before REC starts) -->
                <div
                    class="relative rounded-2xl overflow-hidden bg-black aspect-video shadow-lg"
                    :class="{ hidden: !isRecording }"
                >
                    <video
                        ref="previewEl"
                        class="w-full h-full object-cover"
                        autoplay
                        muted
                        playsinline
                    />
                    <div class="absolute top-3 left-3 flex items-center gap-2 bg-black/60 rounded-full px-3 py-1">
                        <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse" />
                        <span class="text-white text-xs font-semibold uppercase tracking-wide">REC</span>
                    </div>
                </div>

                <!-- Action buttons -->
                <div class="grid grid-cols-1 gap-3">
                    <button
                        type="button"
                        class="w-full rounded-2xl py-5 text-lg font-bold uppercase tracking-wider transition active:scale-[0.98] disabled:opacity-50"
                        :class="isThisDeviceRecording
                            ? 'bg-gray-800 text-white'
                            : 'bg-red-600 text-white shadow-lg shadow-red-200'"
                        :disabled="!isSupported || isTogglingRec || isRegistering"
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

                <!-- Active recorders -->
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
                            <span class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-red-500" />
                                {{ recorder.user_name }}
                                <span
                                    v-if="recorder.recorder_id === recorderId"
                                    class="text-xs text-gray-400"
                                >(você)</span>
                            </span>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-gray-500">Nenhuma câmera gravando.</p>
                </div>

                <!-- Recent saves -->
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
                                :class="pendingSaves[save.uuid]?.received >= pendingSaves[save.uuid]?.expected
                                    ? 'bg-emerald-100 text-emerald-700'
                                    : 'bg-amber-100 text-amber-700'"
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
                                <p class="text-xs font-medium text-gray-600">{{ clip.user_name }}</p>
                                <video
                                    :src="clip.url"
                                    controls
                                    playsinline
                                    preload="metadata"
                                    class="w-full rounded-xl bg-black"
                                />
                            </div>
                        </div>

                        <div v-else class="px-4 py-6 text-center text-sm text-gray-500">
                            <i class="fa-solid fa-spinner fa-spin mr-1" />
                            Aguardando câmeras...
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
