<script setup>
import { onMounted, ref } from 'vue';

defineProps({
    recording: Boolean,
    fullscreen: Boolean,
    cameraTag: String,
    landscapeHint: Boolean,
    canSaveLeft: Boolean,
    canSaveAll: Boolean,
    canSaveRight: Boolean,
    saving: Boolean,
});

const emit = defineEmits(['preview', 'enter-fullscreen', 'exit-fullscreen', 'save', 'stop']);
const videoEl = ref(null);

onMounted(() => emit('preview', videoEl.value));
</script>

<template>
    <section
        class="rec-stage relative rounded-2xl overflow-hidden bg-black shadow-lg"
        :class="[recording ? 'block' : 'hidden', fullscreen ? 'rec-stage--fullscreen' : 'aspect-video']"
    >
        <video ref="videoEl" class="w-full h-full object-cover" autoplay muted playsinline />
        <div class="absolute top-3 left-3 flex items-center gap-2">
            <span class="flex items-center gap-2 bg-black/60 rounded-full px-3 py-1 text-white text-xs font-semibold">
                <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse" /> REC
            </span>
            <span v-if="cameraTag" class="bg-red-600 text-white text-xs font-bold rounded-md px-2.5 py-1">
                {{ cameraTag }}
            </span>
        </div>
        <button
            type="button"
            class="absolute top-3 right-3 bg-black/60 text-white text-xs font-semibold rounded-full px-3 py-1.5"
            @click="fullscreen ? emit('exit-fullscreen') : emit('enter-fullscreen')"
        >
            <i :class="fullscreen ? 'fa-solid fa-compress' : 'fa-solid fa-expand'" class="mr-1" />
            {{ fullscreen ? 'Sair' : 'Tela cheia' }}
        </button>
        <div v-if="landscapeHint && fullscreen" class="absolute inset-x-0 bottom-4 flex justify-center">
            <span class="bg-black/70 text-white text-xs rounded-full px-4 py-2">Gire o celular na horizontal</span>
        </div>
        <div v-if="fullscreen" class="absolute inset-x-0 bottom-6 flex items-end justify-center gap-2 px-4">
            <button
                type="button"
                class="rounded-full bg-emerald-700 text-white font-bold text-xs w-16 h-16 disabled:opacity-50"
                :disabled="!canSaveLeft"
                @click="emit('save', 'left')"
            >← SAVE</button>
            <button
                type="button"
                class="rounded-full bg-emerald-600 text-white font-bold text-sm px-6 h-16 disabled:opacity-50"
                :disabled="!canSaveAll"
                @click="emit('save', 'all')"
            >{{ saving ? 'Salvando...' : 'SAVE REC' }}</button>
            <button
                type="button"
                class="rounded-full bg-emerald-700 text-white font-bold text-xs w-16 h-16 disabled:opacity-50"
                :disabled="!canSaveRight"
                @click="emit('save', 'right')"
            >SAVE →</button>
            <button type="button" class="rounded-full bg-gray-800 text-white font-bold px-5 py-3" @click="emit('stop')">
                Parar
            </button>
        </div>
    </section>
</template>

<style scoped>
.rec-stage--fullscreen {
    position: fixed;
    inset: 0;
    z-index: 80;
    width: 100vw;
    height: 100dvh;
    border-radius: 0;
}
</style>
