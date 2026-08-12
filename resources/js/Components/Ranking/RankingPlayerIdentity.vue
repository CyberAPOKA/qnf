<script setup lang="ts">
import { computed } from 'vue'
import { getRankingStreakType } from '@/Components/Ranking/helpers/getRankingStreakType'

const props = defineProps<{
    name: string
    winStreak?: number
    isGoalkeeper?: boolean
}>()

function firstName(fullName: string): string {
    return fullName.trim().split(/\s+/)[0] || fullName
}

const displayName = computed(() => firstName(props.name))

const streakType = computed(() => getRankingStreakType(props.winStreak ?? 0))

const streakBadgeSrc = computed(() => {
    if (streakType.value === 'dragon') {
        return '/assets/streak/win-streak-7.webp'
    }

    if (streakType.value === 'skulls') {
        return '/assets/streak/win-streak-6.webp'
    }

    if (streakType.value === 'purple') {
        return '/assets/streak/win-streak-5.webp'
    }

    if (streakType.value === 'ice') {
        return '/assets/streak/win-streak-4.webp'
    }

    if (streakType.value === 'fire') {
        return '/assets/streak/win-streak-3.webp'
    }

    return null
})

const streakPhrase = computed(() => {
    if (streakType.value === 'fire') {
        return 'ESTÁ ON FIRE'
    }

    if (streakType.value === 'ice') {
        return 'É GELADO'
    }

    if (streakType.value === 'purple') {
        return 'EL MAGO'
    }

    if (streakType.value === 'skulls') {
        return 'O JUSTICEIRO'
    }

    if (streakType.value === 'dragon') {
        return ''
    }

    return null
})
</script>

<template>
    <div class="relative z-[2] min-w-0 px-2 max-[950px]:px-0">
        <div class="ranking-player-identity inline-flex max-w-full flex-col items-center gap-0.5 px-3 py-1.5 max-[950px]:gap-0.5 max-[950px]:px-1.5 max-[950px]:py-1">
            <h3 class="m-0 inline-flex items-center justify-center gap-1.5 text-center text-[19px] font-black uppercase leading-snug tracking-wide text-slate-50 max-[950px]:gap-1 max-[950px]:text-[16px] max-[950px]:leading-tight">
                <i
                    v-if="isGoalkeeper"
                    class="fa-solid fa-mitten ranking-player-identity__glove"
                    title="Goleiro"
                    aria-hidden="true"
                />
                <span class="min-w-0 truncate">{{ displayName }}</span>
            </h3>

            <p
                v-if="streakPhrase"
                class="ranking-player-identity__streak-phrase italic m-0 text-center text-xs font-black uppercase tracking-[0.18em] max-[950px]:text-[9px] max-[950px]:tracking-[0.1em]"
                style="color: var(--streak-color, var(--accent-light));"
            >
                {{ streakPhrase }}
            </p>

            <img
                v-if="streakBadgeSrc"
                :src="streakBadgeSrc"
                :alt="`Win Streak ${winStreak}`"
                class="h-auto w-[140px] max-w-full max-[950px]:w-[112px]"
            >
        </div>
    </div>
</template>

<style scoped>
.ranking-player-identity {
    border-right: 1px solid rgba(var(--accent-rgb), 0.28);
    border-left: 1px solid rgba(var(--accent-rgb), 0.28);
    background: linear-gradient(90deg, transparent, rgba(var(--accent-rgb), 0.12), transparent);
    clip-path: polygon(8px 0, calc(100% - 8px) 0, 100% 50%, calc(100% - 8px) 100%, 8px 100%, 0 50%);
}

.ranking-player-identity__glove {
    flex: 0 0 auto;
    color: #7dd3fc;
    font-size: 0.78em;
    filter: drop-shadow(0 0 6px rgba(56, 189, 248, 0.65));
}

@media (max-width: 950px) {
    .ranking-player-identity {
        clip-path: polygon(6px 0, calc(100% - 6px) 0, 100% 50%, calc(100% - 6px) 100%, 6px 100%, 0 50%);
    }
}
</style>
