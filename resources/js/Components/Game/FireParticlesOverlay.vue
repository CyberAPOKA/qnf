<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { tsParticles } from '@tsparticles/engine';
import { loadSlim } from '@tsparticles/slim';

const props = defineProps({
    id: { type: [String, Number], required: true },
});

const container = ref(null);
let instance = null;
let loaded = false;

const config = {
    fullScreen: false,
    fpsLimit: 30,
    particles: {
        number: { value: 0 },
        color: { value: ['#ff3b00', '#ff6a00', '#ff9500', '#ffcc00', '#ffee00'] },
        shape: { type: 'circle' },
        opacity: {
            value: { min: 0.4, max: 0.9 },
            animation: { enable: true, speed: 0.6, minimumValue: 0, sync: false },
        },
        size: {
            value: { min: 1.5, max: 4.5 },
            animation: { enable: true, speed: 1.5, minimumValue: 0.5, sync: false },
        },
        move: {
            enable: true,
            speed: { min: 0.3, max: 1.5 },
            direction: 'top',
            outModes: { top: 'destroy', default: 'destroy', bottom: 'none' },
            random: true,
            straight: false,
            drift: { min: -1.5, max: 1.5 },
        },
        life: {
            duration: { value: { min: 1.5, max: 4 } },
            count: 1,
        },
    },
    emitters: {
        position: { x: 50, y: 105 },
        size: { width: 100, height: 0 },
        rate: { quantity: 3, delay: 0.25 },
        life: { duration: 0, count: 0 },
    },
    detectRetina: true,
};

onMounted(async () => {
    if (!container.value) return;
    if (!loaded) {
        await loadSlim(tsParticles);
        loaded = true;
    }
    instance = await tsParticles.load({
        id: `fire-${props.id}`,
        element: container.value,
        options: config,
    });
});

onUnmounted(() => {
    instance?.destroy();
});
</script>

<template>
    <div ref="container" class="fire-particles-container"></div>
</template>

<style scoped>
.fire-particles-container {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 2;
    overflow: hidden;
}
</style>
