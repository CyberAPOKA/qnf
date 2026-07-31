<script setup lang="ts">
import type { PlayerForm } from './types'

defineProps<{
    form: PlayerForm[]
}>()
</script>

<template>
    <div class="ranking-player-form flex items-center justify-center gap-2 max-[950px]:gap-1">
        <span
            v-for="(result, index) in form"
            :key="index"
            class="ranking-player-form__result flex h-[21px] w-[21px] items-center justify-center rounded-full border border-current text-[13px] font-black max-[950px]:h-[17px] max-[950px]:w-[17px] max-[950px]:text-[10px]"
            :class="`ranking-player-form__result--${result}`"
        >
            <template v-if="result === 'win'">✓</template>
            <template v-else-if="result === 'draw'">−</template>
            <template v-else>×</template>
        </span>
    </div>
</template>

<style scoped>
.ranking-player-form__result--win {
    color: #22c55e;
    background: rgba(34, 197, 94, 0.1);
    box-shadow:
        0 0 10px rgba(34, 197, 94, 0.38),
        inset 0 0 7px rgba(34, 197, 94, 0.15);
}

.ranking-player-form__result--draw {
    color: #f59e0b;
    background: rgba(245, 158, 11, 0.1);
    box-shadow: 0 0 10px rgba(245, 158, 11, 0.28);
}

.ranking-player-form__result--loss {
    color: #ef4444;
    background: rgba(239, 68, 68, 0.1);
    box-shadow:
        0 0 10px rgba(239, 68, 68, 0.35),
        inset 0 0 7px rgba(239, 68, 68, 0.15);
}
</style>
