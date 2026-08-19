<script setup lang="ts">
import { computed } from 'vue'
import type { PlayerCustomizationFlag } from '@/Components/Ranking/types'

const props = defineProps<{
    flag?: PlayerCustomizationFlag | string | null
}>()

const visibleFlag = computed<PlayerCustomizationFlag | null>(() => {
    if (props.flag === 'B' || props.flag === 'L') {
        return props.flag
    }

    return null
})
</script>

<template>
    <div
        v-if="visibleFlag"
        class="ranking-player-flag pointer-events-none absolute z-30 flex flex-col items-center"
        :class="`ranking-player-flag--${visibleFlag}`"
        aria-hidden="true"
    >
        <div class="ranking-player-flag__balloon">
            <img
                v-if="visibleFlag === 'B'"
                class="ranking-player-flag__br"
                src="/assets/svgs/br.svg"
                alt=""
            >
            <svg
                v-else
                class="ranking-player-flag__star"
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path d="M12 2.1 14.9 8l6.6.9-4.8 4.7 1.2 6.6L12 17.2 6.1 20.2l1.2-6.6L2.5 8.9 9.1 8z" />
            </svg>
        </div>
        <span class="ranking-player-flag__dot" />
    </div>
</template>

<style scoped>
.ranking-player-flag {
    --flag-width: 42px;
    --flag-height: 36px;
    --flag-dot: 8px;
    --flag-nub: 10px;
    left: 27%;
    top: -4px;
    transform: translateX(-50%);
    user-select: none;
}

.ranking-player-flag--B {
    --flag-color: #22c55e;
}

.ranking-player-flag--L {
    --flag-color: #ef1f32;
}

.ranking-player-flag__balloon {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--flag-width);
    height: var(--flag-height);
    border-radius: 11px;
    background: var(--flag-color);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.22),
        0 2px 6px rgba(0, 0, 0, 0.35);
}

.ranking-player-flag__balloon::after {
    content: '';
    position: absolute;
    left: 50%;
    bottom: -5px;
    width: var(--flag-nub);
    height: var(--flag-nub);
    border-radius: 999px;
    background: var(--flag-color);
    transform: translateX(-50%);
}

.ranking-player-flag__br {
    display: block;
    width: 72%;
    height: auto;
    border-radius: 2px;
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.08);
}

.ranking-player-flag__star {
    width: 68%;
    height: 68%;
    fill: #ffd54a;
    stroke: #1a1a1a;
    stroke-width: 0.7;
    filter: drop-shadow(0 1px 0 rgba(0, 0, 0, 0.2));
}

.ranking-player-flag__dot {
    display: block;
    width: var(--flag-dot);
    height: var(--flag-dot);
    margin-top: 8px;
    flex-shrink: 0;
    border-radius: 999px;
    background: var(--flag-color);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.28);
    margin-left: 7px;
}

@media (max-width: 950px) {
    .ranking-player-flag {
        --flag-width: 34px;
        --flag-height: 30px;
        --flag-dot: 6px;
        --flag-nub: 8px;
        left: 20%;
        top: 4px;
    }

    .ranking-player-flag__balloon {
        border-radius: 9px;
    }

    .ranking-player-flag__balloon::after {
        bottom: -4px;
    }

    .ranking-player-flag__dot {
        margin-top: 5px;
        margin-left: 7px;
    }
}
</style>
