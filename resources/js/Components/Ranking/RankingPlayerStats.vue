<script setup lang="ts">
defineProps<{
    points: number
    games: number
    movement: number | null
    pointsLabel?: string
    avg?: number | null
}>()
</script>

<template>
    <div class="ranking-player-stats flex items-center justify-center gap-1 px-1 max-[950px]:gap-0.5 max-[950px]:pr-1.5 max-[950px]:pl-0">
        <div class="flex min-w-[46px] flex-col items-center justify-center max-[950px]:min-w-[34px]">
            <strong class="font-special text-[21px] font-black leading-none text-slate-50 max-[950px]:text-[15px]">{{ points }}</strong>
            <span class="font-display text-[9px] font-extrabold tracking-widest text-slate-500 max-[950px]:text-[8px] max-[950px]:tracking-wide">
                {{ pointsLabel || 'PTS' }}
            </span>
        </div>

        <div
            v-if="movement !== null && movement !== 0"
            class="font-special flex min-w-8 items-center justify-center gap-0.5 text-[12px] font-extrabold max-[950px]:min-w-[20px] max-[950px]:text-[10px]"
            :class="movement > 0 ? 'text-green-500' : 'text-red-500'"
        >
            <template v-if="movement > 0">
                <span>▲</span> {{ movement }}
            </template>
            <template v-else>
                <span>▼</span> {{ Math.abs(movement) }}
            </template>
        </div>

        <div
            v-else-if="avg != null"
            class="flex min-w-[46px] flex-col items-center justify-center max-[950px]:min-w-[34px]"
        >
            <strong class="font-special text-[21px] font-black leading-none text-slate-50 max-[950px]:text-[15px]">{{ avg }}</strong>
            <span class="font-display text-[9px] font-extrabold tracking-widest text-slate-500 max-[950px]:text-[8px] max-[950px]:tracking-wide">MÉDIA</span>
        </div>

        <div class="flex min-w-[46px] flex-col items-center justify-center max-[950px]:min-w-[34px]">
            <strong class="font-special text-[21px] font-black leading-none text-slate-50 max-[950px]:text-[15px]">{{ games }}</strong>
            <span class="font-display text-[9px] font-extrabold tracking-widest text-slate-500 max-[950px]:text-[8px] max-[950px]:tracking-wide">PJ</span>
        </div>
    </div>
</template>
