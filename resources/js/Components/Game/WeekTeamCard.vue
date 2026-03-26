<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    images: { type: Array, default: () => [] },
});

const currentIndex = ref(0);
const isPlaying = ref(false);
const audio = ref(null);
const glitching = ref(false);

let interval = null;
let glitchInterval = null;
let glitchDelay = null;
let glitchRunning = false;

const flash = (duration = 100) => new Promise(resolve => {
    glitching.value = true;
    setTimeout(() => { glitching.value = false; setTimeout(resolve, 80); }, duration);
});

const runGlitchCycle = async () => {
    await flash(100);
    await flash(100);
    await flash(100);
    await new Promise(r => setTimeout(r, 1000));
    await flash(100);
    await flash(100);
};

const startGlitchLoop = () => {
    if (glitchRunning) return;
    glitchRunning = true;
    const loop = async () => {
        if (!glitchRunning) return;
        await runGlitchCycle();
        if (!glitchRunning) return;
        glitchInterval = setTimeout(loop, 6000);
    };
    loop();
};

const stopGlitchLoop = () => {
    glitchRunning = false;
    glitching.value = false;
    if (glitchInterval) { clearTimeout(glitchInterval); glitchInterval = null; }
    if (glitchDelay) { clearTimeout(glitchDelay); glitchDelay = null; }
};

const scheduleGlitch = () => {
    stopGlitchLoop();
    glitchDelay = setTimeout(() => startGlitchLoop(), 21000);
};

const toggleMusic = () => {
    if (!audio.value) return;
    if (isPlaying.value) {
        audio.value.pause();
        isPlaying.value = false;
        stopGlitchLoop();
    } else {
        audio.value.play();
        isPlaying.value = true;
        scheduleGlitch();
    }
};

const tryAutoplay = () => {
    if (!audio.value || isPlaying.value) return;
    audio.value.play().then(() => {
        isPlaying.value = true;
        scheduleGlitch();
    }).catch(() => {
        const playOnInteraction = () => {
            if (audio.value && !isPlaying.value) {
                audio.value.play().then(() => {
                    isPlaying.value = true;
                    scheduleGlitch();
                }).catch(() => {});
            }
            document.removeEventListener('click', playOnInteraction);
            document.removeEventListener('touchstart', playOnInteraction);
        };
        document.addEventListener('click', playOnInteraction, { once: true });
        document.addEventListener('touchstart', playOnInteraction, { once: true });
    });
};

onMounted(() => {
    if (props.images.length > 1) {
        interval = setInterval(() => {
            currentIndex.value = (currentIndex.value + 1) % props.images.length;
        }, 10000);
    }

    if (props.images.length) {
        tryAutoplay();
    }
});

onUnmounted(() => {
    if (interval) clearInterval(interval);
    stopGlitchLoop();
    if (audio.value) {
        audio.value.pause();
        audio.value = null;
    }
});

const downloadAll = () => {
    props.images.forEach((src, i) => {
        const a = document.createElement('a');
        a.href = src;
        a.download = `time-da-semana-${i + 1}.png`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    });
};
</script>

<template>
    <div v-if="images.length" class="rounded-xl bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 p-3 shadow-lg">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-bold text-yellow-400">
                <i class="fa-solid fa-star mr-2"></i>
                <span v-if="images.length > 1">Times da semana</span>
                <span v-else>Time da Semana</span>
            </h3>
            <div class="flex items-center gap-2">
                <button @click="downloadAll"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold bg-gray-700 text-gray-300 hover:bg-gray-600 transition">
                    <i class="fa-solid fa-download"></i>
                    Baixar
                </button>
                <button @click="toggleMusic"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition"
                    :class="isPlaying
                        ? 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'
                        : 'bg-gray-700 text-gray-300 hover:bg-gray-600'">
                    <i :class="isPlaying ? 'fa-solid fa-pause' : 'fa-solid fa-play'"></i>
                    {{ isPlaying ? 'Pausar' : 'Tocar' }}
                </button>
            </div>
        </div>

        <div class="relative overflow-hidden rounded-lg">
            <transition name="fade">
                <img :key="currentIndex" :src="images[currentIndex]" alt="Time da Semana"
                    class="w-full rounded-lg absolute inset-0"
                    :class="{ 'glitch-flash': glitching }" />
            </transition>
            <img :src="images[0]" alt="" class="w-full rounded-lg invisible" aria-hidden="true" />

            <div v-if="images.length > 1" class="flex justify-center gap-2 mt-2">
                <button v-for="(_, i) in images" :key="i" @click="currentIndex = i"
                    class="h-2.5 w-2.5 rounded-full transition"
                    :class="i === currentIndex ? 'bg-yellow-400' : 'bg-gray-600'" />
            </div>
        </div>

        <audio ref="audio" loop preload="none">
            <source src="/sounds/WhatsUpDanger.mp3" type="audio/mpeg" />
        </audio>
    </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 1.2s ease-in-out;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.fade-leave-active {
    position: absolute;
    inset: 0;
}

.glitch-flash {
    filter: invert(1) hue-rotate(180deg) saturate(2.5) brightness(1.3);
    transition: filter 0.05s;
}
</style>
