import { onMounted, onUnmounted, watch } from 'vue';
import { tsParticles } from '@tsparticles/engine';
import { loadSlim } from '@tsparticles/slim';

let engineLoaded = false;

async function ensureEngine() {
    if (!engineLoaded) {
        await loadSlim(tsParticles);
        engineLoaded = true;
    }
}

const fireConfig = {
    fullScreen: false,
    fpsLimit: 60,
    particles: {
        number: { value: 25, density: { enable: true, width: 400, height: 100 } },
        color: { value: ['#ff3b00', '#ff6a00', '#ff9500', '#ffcc00', '#ffee00'] },
        shape: { type: 'circle' },
        opacity: {
            value: { min: 0.3, max: 0.8 },
            animation: { enable: true, speed: 0.8, minimumValue: 0, sync: false },
        },
        size: {
            value: { min: 1, max: 4 },
            animation: { enable: true, speed: 2, minimumValue: 0.5, sync: false },
        },
        move: {
            enable: true,
            speed: { min: 0.5, max: 2 },
            direction: 'top',
            outModes: { top: 'destroy', default: 'destroy', bottom: 'none' },
            random: true,
            straight: false,
            drift: { min: -1, max: 1 },
        },
        life: {
            duration: { value: { min: 1, max: 3 } },
            count: 1,
        },
    },
    emitters: {
        position: { x: 50, y: 100 },
        size: { width: 100, height: 0 },
        rate: { quantity: 2, delay: 0.3 },
        life: { duration: 0, count: 0 },
    },
    detectRetina: true,
};

export function useFireParticles() {
    const instances = new Map();

    async function attach(element, id) {
        if (!element || instances.has(id)) return;

        await ensureEngine();

        const container = document.createElement('div');
        container.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:1;overflow:hidden;';
        container.id = `fire-particles-${id}`;

        element.style.position = 'relative';
        element.appendChild(container);

        const instance = await tsParticles.load({
            id: container.id,
            element: container,
            options: fireConfig,
        });

        instances.set(id, { container, instance });
    }

    function detach(id) {
        const entry = instances.get(id);
        if (entry) {
            entry.instance?.destroy();
            entry.container?.remove();
            instances.delete(id);
        }
    }

    function detachAll() {
        for (const id of instances.keys()) {
            detach(id);
        }
    }

    onUnmounted(detachAll);

    return { attach, detach, detachAll };
}
