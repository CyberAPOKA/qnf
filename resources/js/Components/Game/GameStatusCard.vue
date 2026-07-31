<script setup>
import { computed } from 'vue';
import HudSelect from '@/Components/HudSelect.vue';

const props = defineProps({
    statusLabel: String,
    status: String,
    playersCount: Number,
    round: {
        type: Number,
        default: null,
    },
    rounds: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['update:round']);

const showPlayersCount = computed(
    () => !['scheduled', 'drafted', 'done'].includes(props.status),
);

const showDetails = computed(() => !['drafted', 'done'].includes(props.status));

const roundOptions = computed(() => props.rounds || []);
</script>

<template>
    <section class="hud">
        <div class="hud__inner">
            <span class="hud__corner hud__corner--tl" aria-hidden="true" />
            <span class="hud__corner hud__corner--tr" aria-hidden="true" />
            <span class="hud__corner hud__corner--bl" aria-hidden="true" />
            <span class="hud__corner hud__corner--br" aria-hidden="true" />
            <span class="hud__ear hud__ear--left" aria-hidden="true" />
            <span class="hud__ear hud__ear--right" aria-hidden="true" />
            <span class="hud__edge hud__edge--top" aria-hidden="true" />
            <span class="hud__edge hud__edge--bottom" aria-hidden="true" />
            <span class="hud__stud hud__stud--tl" aria-hidden="true" />
            <span class="hud__stud hud__stud--tr" aria-hidden="true" />
            <span class="hud__stud hud__stud--bl" aria-hidden="true" />
            <span class="hud__stud hud__stud--br" aria-hidden="true" />

            <div class="hud__thunder" aria-hidden="true">
                <video
                    class="hud__thunder-video"
                    autoplay
                    loop
                    muted
                    playsinline
                    preload="auto"
                >
                    <source src="/assets/streak/thunder.webm" type="video/webm">
                </video>
            </div>

            <div class="hud__runners" aria-hidden="true">
                <span class="hud__runner hud__runner--top" />
                <span class="hud__runner hud__runner--right" />
                <span class="hud__runner hud__runner--bottom" />
                <span class="hud__runner hud__runner--left" />
            </div>

            <header v-if="round" class="hud__head">
                <i class="fa-regular fa-gem hud__gem" aria-hidden="true" />

                <HudSelect
                    class="hud__select"
                    :model-value="round"
                    :options="roundOptions"
                    :option-label="(r) => `Rodada ${r}`"
                    :disabled="roundOptions.length <= 1"
                    @update:model-value="emit('update:round', $event)"
                />

                <i class="fa-regular fa-gem hud__gem" aria-hidden="true" />
            </header>

            <div v-if="round" class="hud__divider" aria-hidden="true">
                <span class="hud__hash">
                    <i v-for="n in 4" :key="n" class="hud__hash-bar" />
                </span>
                <span class="hud__line" />
                <span class="hud__hash hud__hash--right">
                    <i v-for="n in 4" :key="n" class="hud__hash-bar" />
                </span>
            </div>

            <div class="hud__body">
                <p v-if="showPlayersCount" class="hud__meta">
                    Inscritos
                    <span class="hud__meta-value">{{ playersCount }}/15</span>
                </p>

                <slot v-if="showDetails" name="details" />

                <div v-if="status !== 'done'" class="hud__actions mb-4">
                    <slot name="actions" />
                </div>

                <slot name="footer" />
            </div>

            <span class="hud__ticks" aria-hidden="true">
                <i v-for="n in 4" :key="n" class="hud__tick" />
            </span>
        </div>
    </section>
</template>

<style scoped>
.hud {
    --hud-cyan: #38bdf8;
    --hud-blue: #1d4ed8;
    --cut: 26px;

    position: relative;
    padding: 6px;
    background:
        linear-gradient(135deg,
            rgba(56, 189, 248, 0.95) 0%,
            rgba(29, 78, 216, 0.45) 24%,
            rgba(15, 23, 42, 0.7) 50%,
            rgba(29, 78, 216, 0.45) 76%,
            rgba(56, 189, 248, 0.95) 100%);
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
    filter: drop-shadow(0 0 20px rgba(56, 189, 248, 0.28));
}

.hud__inner {
    --cut: 21px;

    position: relative;
    padding: 14px 14px 24px;
    text-align: center;
    background:
        radial-gradient(90% 70% at 50% 0%, rgba(56, 189, 248, 0.14), transparent 60%),
        radial-gradient(70% 60% at 50% 120%, rgba(37, 99, 235, 0.18), transparent 65%),
        linear-gradient(180deg, #0a1524 0%, #050810 100%);
    box-shadow: inset 0 0 0 1px rgba(56, 189, 248, 0.45);
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
}

.hud__corner {
    position: absolute;
    width: 18px;
    height: 18px;
    border: 2px solid var(--hud-cyan);
    filter: drop-shadow(0 0 6px rgba(56, 189, 248, 0.8));
    pointer-events: none;
}

.hud__corner--tl {
    top: 16px;
    left: 16px;
    border-right: 0;
    border-bottom: 0;
}

.hud__corner--tr {
    top: 16px;
    right: 16px;
    border-bottom: 0;
    border-left: 0;
}

.hud__corner--bl {
    bottom: 16px;
    left: 16px;
    border-top: 0;
    border-right: 0;
}

.hud__corner--br {
    right: 16px;
    bottom: 16px;
    border-top: 0;
    border-left: 0;
}

.hud__ear {
    position: absolute;
    top: 50%;
    width: 7px;
    height: 46px;
    background: linear-gradient(180deg, rgba(56, 189, 248, 0.25), var(--hud-cyan), rgba(56, 189, 248, 0.25));
    opacity: 0.9;
    filter: drop-shadow(0 0 6px rgba(56, 189, 248, 0.7));
    clip-path: polygon(0 0, 100% 14px, 100% calc(100% - 14px), 0 100%);
    pointer-events: none;
}

.hud__ear--left {
    left: 2px;
    transform: translateY(-50%);
}

.hud__ear--right {
    right: 2px;
    transform: translateY(-50%) scaleX(-1);
}

/* Trapézios no meio das arestas superior e inferior */
.hud__edge {
    position: absolute;
    left: 50%;
    width: 96px;
    height: 7px;
    background: linear-gradient(90deg,
        rgba(56, 189, 248, 0.15),
        var(--hud-cyan) 35%,
        var(--hud-cyan) 65%,
        rgba(56, 189, 248, 0.15));
    transform: translateX(-50%);
    opacity: 0.9;
    filter: drop-shadow(0 0 7px rgba(56, 189, 248, 0.65));
    pointer-events: none;
}

.hud__edge--top {
    top: 0;
    clip-path: polygon(0 0, 100% 0, calc(100% - 16px) 100%, 16px 100%);
}

.hud__edge--bottom {
    bottom: 0;
    clip-path: polygon(16px 0, calc(100% - 16px) 0, 100% 100%, 0 100%);
}

/* Losangos entre os cantos e os trapézios centrais */
.hud__stud {
    position: absolute;
    width: 9px;
    height: 9px;
    background: rgba(125, 211, 252, 0.9);
    filter: drop-shadow(0 0 6px rgba(56, 189, 248, 0.8));
    clip-path: polygon(50% 0, 100% 50%, 50% 100%, 0 50%);
    pointer-events: none;
}

.hud__stud--tl,
.hud__stud--tr {
    top: 3px;
}

.hud__stud--bl,
.hud__stud--br {
    bottom: 3px;
}

.hud__stud--tl,
.hud__stud--bl {
    left: 25%;
}

.hud__stud--tr,
.hud__stud--br {
    right: 25%;
}

.hud__thunder {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    mix-blend-mode: screen;
    pointer-events: none;
}

.hud__thunder-video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.45;
    filter: brightness(1.1) contrast(1.2) saturate(1.3);
}

.hud__runners {
    position: absolute;
    inset: 0;
    z-index: 2;
    /* clip-path recorta a pintura mas não o overflow: sem isto os raios
       fora do card esticam a página e reescalam o background */
    overflow: hidden;
    pointer-events: none;
}

.hud__runner {
    position: absolute;
    display: block;
    filter:
        drop-shadow(0 0 4px rgba(255, 255, 255, 1))
        drop-shadow(0 0 8px rgba(72, 186, 255, 1))
        drop-shadow(0 0 12px rgba(75, 82, 255, 0.9));
}

.hud__runner--top,
.hud__runner--bottom {
    width: 45%;
    height: 3px;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(92, 138, 255, 0.25),
        #ffffff,
        #58c7ff,
        transparent
    );
}

.hud__runner--left,
.hud__runner--right {
    width: 3px;
    height: 45%;
    background: linear-gradient(
        180deg,
        transparent,
        rgba(92, 138, 255, 0.25),
        #ffffff,
        #58c7ff,
        transparent
    );
}

.hud__runner--top {
    top: 0;
    left: -45%;
    animation: hudRunnerTop 2.2s linear infinite;
}

.hud__runner--right {
    top: -45%;
    right: 0;
    animation: hudRunnerRight 2.2s linear infinite 0.55s;
}

.hud__runner--bottom {
    right: -45%;
    bottom: 0;
    animation: hudRunnerBottom 2.2s linear infinite 1.1s;
}

.hud__runner--left {
    bottom: -45%;
    left: 0;
    animation: hudRunnerLeft 2.2s linear infinite 1.65s;
}

.hud__head,
.hud__divider,
.hud__body {
    position: relative;
    z-index: 3;
}

.hud__head {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 0 6px;
}

.hud__gem {
    flex: 0 0 auto;
    color: #7dd3fc;
    font-size: 1.6rem;
    filter: drop-shadow(0 0 10px rgba(56, 189, 248, 0.85));
    animation: hudGemPulse 2.4s ease-in-out infinite alternate;
}

.hud__gem:last-child {
    animation-delay: 0.6s;
}

.hud__select {
    flex: 0 1 auto;
    min-width: 0;
}

.hud__divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 12px 4px 0;
}

.hud__line {
    flex: 1 1 auto;
    height: 2px;
    background: linear-gradient(90deg,
        transparent,
        rgba(56, 189, 248, 0.15) 12%,
        rgba(56, 189, 248, 0.9) 50%,
        rgba(56, 189, 248, 0.15) 88%,
        transparent);
    box-shadow: 0 0 10px rgba(56, 189, 248, 0.5);
}

/* Barras individuais (em vez de gradiente repetido) para nenhuma listra sair cortada */
.hud__hash {
    display: flex;
    flex: 0 0 auto;
    gap: 5px;
    padding-inline: 3px;
    opacity: 0.85;
}

.hud__hash--right {
    transform: scaleX(-1);
}

.hud__hash-bar {
    display: block;
    width: 3px;
    height: 11px;
    background: linear-gradient(180deg, rgba(125, 211, 252, 0.95), rgba(56, 189, 248, 0.5));
    transform: skewX(-22deg);
    filter: drop-shadow(0 0 4px rgba(56, 189, 248, 0.55));
}

.hud__body {
    padding-top: 12px;
}

.hud__meta {
    margin: 0;
    color: #94a3b8;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
}

.hud__meta-value {
    color: #e2e8f0;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-weight: 700;
    letter-spacing: 0.04em;
}

.hud__actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 10px;
}

.hud__ticks {
    position: absolute;
    bottom: 10px;
    left: 50%;
    z-index: 3;
    display: flex;
    gap: 7px;
    transform: translateX(-50%);
    pointer-events: none;
}

.hud__tick {
    display: block;
    width: 4px;
    height: 9px;
    background: linear-gradient(180deg, #7dd3fc, rgba(56, 189, 248, 0.5));
    transform: skewX(-22deg);
    filter: drop-shadow(0 0 5px rgba(56, 189, 248, 0.7));
}

@keyframes hudRunnerTop {
    0% {
        left: -45%;
        opacity: 0;
    }

    10%,
    90% {
        opacity: 1;
    }

    100% {
        left: 100%;
        opacity: 0;
    }
}

@keyframes hudRunnerRight {
    0% {
        top: -45%;
        opacity: 0;
    }

    10%,
    90% {
        opacity: 1;
    }

    100% {
        top: 100%;
        opacity: 0;
    }
}

@keyframes hudRunnerBottom {
    0% {
        right: -45%;
        opacity: 0;
    }

    10%,
    90% {
        opacity: 1;
    }

    100% {
        right: 100%;
        opacity: 0;
    }
}

@keyframes hudRunnerLeft {
    0% {
        bottom: -45%;
        opacity: 0;
    }

    10%,
    90% {
        opacity: 1;
    }

    100% {
        bottom: 100%;
        opacity: 0;
    }
}

@keyframes hudGemPulse {
    0% {
        transform: translateY(1px) scale(0.97);
        filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.6));
    }

    100% {
        transform: translateY(-2px) scale(1.06);
        filter: drop-shadow(0 0 16px rgba(125, 211, 252, 0.95));
    }
}

@media (min-width: 640px) {
    .hud {
        --cut: 32px;
        padding: 7px;
    }

    .hud__inner {
        --cut: 26px;
        padding: 20px 22px 30px;
    }

    .hud__edge {
        width: 140px;
        height: 8px;
    }

    .hud__gem {
        font-size: 2.4rem;
    }

    .hud__head {
        gap: 22px;
    }

    .hud__corner {
        width: 24px;
        height: 24px;
    }

    .hud__ear {
        height: 60px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .hud__gem,
    .hud__runner {
        animation: none;
    }

    .hud__thunder {
        display: none;
    }
}
</style>
