<script setup>
defineProps({
    badge: {
        type: String,
        default: '',
    },
})
</script>

<template>
    <div
        class="relative w-full rounded-2xl bg-[#0b0908] p-1 shadow-[0_0_20px_rgba(255,120,30,0.18)]">
        <div
            class="electric-frame relative h-full w-full overflow-hidden rounded-xl bg-[linear-gradient(180deg,#1a130f_0%,#0c0a09_100%)]">
            <div class="electric-scan absolute inset-0 pointer-events-none"></div>
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.06] bg-[repeating-linear-gradient(180deg,rgba(255,255,255,0.2)_0px,rgba(255,255,255,0.2)_1px,transparent_2px,transparent_4px)]">
            </div>

            <div class="relative z-[2] flex h-full flex-col p-2 sm:p-4">
                <div v-if="badge" class="mb-3">
                    <span
                        class="inline-flex rounded-full border border-white/20 bg-white/5 px-3 py-1 text-[10px] font-semibold tracking-wide text-[#f7e7d2]">
                        {{ badge }}
                    </span>
                </div>

                <div class="flex h-full w-full flex-col">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.electric-frame::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 0.8rem;
    padding: 4px;
    background: linear-gradient(120deg,
            #ffb973,
            #ff6a00,
            #ffd8a8,
            #ff6a00,
            #ffb973);
    background-size: 300% 300%;
    -webkit-mask:
        linear-gradient(#000 0 0) content-box,
        linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}

.electric-scan {
    background: linear-gradient(transparent,
            rgba(255, 140, 50, 0.14),
            transparent);
    mix-blend-mode: screen;
    animation: scanMove 2.5s linear infinite;
}

@keyframes borderFlow {
    0% {
        background-position: 0% 50%;
    }

    100% {
        background-position: 300% 50%;
    }
}

@keyframes glowPulse {
    0% {
        filter: drop-shadow(0 0 5px #ff7a2f);
    }

    100% {
        filter: drop-shadow(0 0 18px #ff7a2f) drop-shadow(0 0 36px #ff5a1f);
    }
}

@keyframes sparks {
    0% {
        opacity: .65;
        transform: translate(0, 0);
    }

    50% {
        opacity: 1;
        transform: translate(1px, -1px);
    }

    100% {
        opacity: .65;
        transform: translate(-1px, 1px);
    }
}

@keyframes scanMove {
    0% {
        transform: translateY(-100%);
    }

    100% {
        transform: translateY(100%);
    }
}
</style>