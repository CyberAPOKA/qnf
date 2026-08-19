<script setup lang="ts">
import { computed } from 'vue'
import RankingPlayerCustomizationBubble from '@/Components/Ranking/RankingPlayerCustomizationBubble.vue'
import type { PlayerCustomizations, RankingTheme } from '@/Components/Ranking/types'

const props = defineProps<{
    src?: string | null
    name: string
    initial?: string
    theme?: RankingTheme
    customizations?: PlayerCustomizations | null
}>()

const isPodium = computed(() =>
    props.theme === 'gold'
    || props.theme === 'silver'
    || props.theme === 'bronze',
)
</script>

<template>
    <div class="ranking-player-photo relative flex items-end justify-center self-stretch"
        :class="{ 'ranking-player-photo--podium': isPodium }">
        <RankingPlayerCustomizationBubble :flag="customizations?.flag" />

        <!-- <div class="ranking-player-photo__glow pointer-events-none absolute bottom-[-10px] left-1/2 z-[1] -translate-x-1/2 rounded-full" /> -->

        <!-- Pódio: foto fora do mask/crop para atravessar a borda -->
        <template v-if="isPodium">
            <img v-if="src" :src="src" :alt="name" class="ranking-player-photo__breakout" loading="lazy"
                decoding="async">
            <div v-else
                class="ranking-player-photo__breakout-fallback flex items-center justify-center rounded-full border-2 font-black uppercase text-slate-50"
                style="background: rgba(var(--accent-rgb), 0.25); border-color: rgba(var(--accent-rgb), 0.55);">
                {{ initial || name?.charAt(0) || '?' }}
            </div>
        </template>

        <div v-else
            class="ranking-player-photo__crop relative z-[2] flex h-full w-full items-end justify-center overflow-hidden">
            <img v-if="src" :src="src" :alt="name"
                class="ranking-player-photo__img relative z-[2] block object-cover object-top saturate-[1.05] contrast-[1.04]"
                loading="lazy" decoding="async">
            <div v-else
                class="ranking-player-photo__fallback relative z-[2] mb-4 flex items-center justify-center rounded-full border-2 font-black uppercase text-slate-50"
                style="background: rgba(var(--accent-rgb), 0.25); border-color: rgba(var(--accent-rgb), 0.55);">
                {{ initial || name?.charAt(0) || '?' }}
            </div>
        </div>
    </div>
</template>

<style scoped>
.ranking-player-photo {
    overflow: visible;
    min-height: 0;
}

.ranking-player-photo__glow {
    height: 100px;
    width: 230px;
    background: rgba(var(--accent-rgb), 0.35);
    filter: blur(32px);
}

.ranking-player-photo__img {
    height: 100px;
    width: 148px;
}

.ranking-player-photo__fallback {
    height: 62px;
    width: 62px;
    font-size: 24px;
}

.ranking-player-photo__crop {
    -webkit-mask-image: linear-gradient(to top, transparent 0, #000 32px);
    mask-image: linear-gradient(to top, transparent 0, #000 32px);
}

.ranking-player-photo--podium {
    overflow: visible !important;
}

.ranking-player-photo--podium :deep(.ranking-player-flag) {
    top: -30px;
    left: 25%;
}

.ranking-player-photo--podium .ranking-player-photo__glow {
    bottom: 0;
    z-index: 1;
    height: 78px;
    width: 190px;
    opacity: 0.85;
}

.ranking-player-photo__breakout {
    position: absolute;
    left: 50%;
    bottom: 0;
    z-index: 20;
    width: 142px;
    height: calc(100% + 26px);
    max-width: none;
    object-fit: cover;
    object-position: top center;
    transform: translateX(-50%);
    pointer-events: none;
    -webkit-mask-image: linear-gradient(to top, transparent 0%, rgba(0, 0, 0, 0.35) 18%, #000 38%);
    mask-image: linear-gradient(to top, transparent 0%, rgba(0, 0, 0, 0.35) 18%, #000 38%);
}

.ranking-player-photo__breakout-fallback {
    position: absolute;
    left: 50%;
    bottom: 10px;
    z-index: 20;
    width: 62px;
    height: 62px;
    font-size: 24px;
    transform: translateX(-50%);
}

@media (max-width: 950px) {
    .ranking-player-photo__img {
        height: 96px;
        width: 112px;
    }

    .ranking-player-photo__fallback {
        height: 48px;
        width: 48px;
        margin-bottom: 0.375rem;
        font-size: 1.125rem;
    }

    .ranking-player-photo__glow {
        bottom: -6px;
        height: 88px;
        width: 180px;
    }

    .ranking-player-photo__crop {
        -webkit-mask-image: linear-gradient(to top, transparent 0, #000 28px);
        mask-image: linear-gradient(to top, transparent 0, #000 28px);
    }

    .ranking-player-photo__breakout {
        width: 108px;
        height: calc(100% + 16px);
    }

    .ranking-player-photo__breakout-fallback {
        width: 50px;
        height: 50px;
        font-size: 1.15rem;
    }

    .ranking-player-photo--podium :deep(.ranking-player-flag) {
        top: -22px;
        left: 17%;
    }
}
</style>
