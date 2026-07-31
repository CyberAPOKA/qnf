<script setup lang="ts">
import { computed } from 'vue'
import { MEDAL_ICONS, type RankingTheme } from './types'

const props = defineProps<{
    rank: number
    theme: RankingTheme
}>()

const medalIcon = computed(() => MEDAL_ICONS[props.theme] ?? null)
</script>

<template>
    <div class="flex flex-col items-center justify-center">
        <div
            class="ranking-rank-badge relative flex items-center justify-center"
            :class="medalIcon
                ? 'h-[86px] w-[72px] max-[950px]:h-[64px] max-[950px]:w-[52px]'
                : 'h-[78px] w-[66px] max-[950px]:h-[58px] max-[950px]:w-[52px]'"
        >
            <div
                v-if="medalIcon"
                class="ranking-rank-badge__medal relative flex h-full w-full items-center justify-center"
            >
                <img
                    :src="medalIcon"
                    :alt="`${rank}º lugar`"
                    class="pointer-events-none block h-full w-full select-none object-contain"
                    loading="lazy"
                    decoding="async"
                >
            </div>

            <div
                v-else
                class="ranking-rank-badge__polygon relative flex h-[66px] w-[58px] items-center justify-center max-[950px]:h-[54px] max-[950px]:w-[48px]"
            >
                <strong
                    class="ranking-rank-badge__number relative z-[2]"
                    :class="{ 'ranking-rank-badge__number--wide': rank >= 10 }"
                >
                    {{ rank }}
                </strong>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ranking-rank-badge__medal {
    filter:
        drop-shadow(0 0 8px rgba(var(--accent-rgb), 0.75))
        drop-shadow(0 0 20px rgba(var(--accent-rgb), 0.45));
    animation: ranking-medal-float 3s ease-in-out infinite;
}

@keyframes ranking-medal-float {
    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-5px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .ranking-rank-badge__medal {
        animation: none;
    }
}

.ranking-rank-badge__polygon {
    background:
        linear-gradient(
            145deg,
            #fff8e7 0%,
            var(--accent-light) 18%,
            var(--accent) 48%,
            color-mix(in srgb, var(--accent) 55%, #000) 78%,
            var(--accent-light) 100%
        );
    filter:
        drop-shadow(0 0 8px rgba(var(--accent-rgb), 0.75))
        drop-shadow(0 0 20px rgba(var(--accent-rgb), 0.45));
    clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
}

.ranking-rank-badge__polygon::before {
    content: '';
    position: absolute;
    inset: 6px;
    background:
        radial-gradient(ellipse at 50% 30%, rgba(var(--accent-rgb), 0.28), transparent 55%),
        linear-gradient(180deg, #2a2433 0%, #0c0d12 55%, #050608 100%);
    box-shadow: inset 0 0 14px rgba(0, 0, 0, 0.85);
    clip-path: inherit;
}

.ranking-rank-badge__polygon::after {
    content: '';
    position: absolute;
    inset: 6px;
    z-index: 1;
    box-shadow:
        inset 0 0 0 1px rgba(var(--accent-rgb), 0.35),
        inset 0 0 12px rgba(var(--accent-rgb), 0.12);
    clip-path: inherit;
    pointer-events: none;
}

.ranking-rank-badge__number {
    position: relative;
    z-index: 2;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 24px;
    font-weight: 900;
    font-style: normal;
    line-height: 1;
    letter-spacing: -0.04em;
    text-align: center;
    transform: skewX(-8deg);
    background: linear-gradient(180deg, #fff 0%, var(--accent-light) 42%, var(--accent) 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    filter: drop-shadow(0 0 10px rgba(var(--accent-rgb), 0.65));
}

.ranking-rank-badge__number--wide {
    font-size: 19px;
    letter-spacing: -0.06em;
}

@media (max-width: 950px) {
    .ranking-rank-badge__polygon::before,
    .ranking-rank-badge__polygon::after {
        inset: 3px;
    }

    .ranking-rank-badge__number {
        font-size: 22px;
        transform: skewX(-6deg);
    }

    .ranking-rank-badge__number--wide {
        font-size: 16px;
    }
}
</style>
