import type { RankingStreakType } from '@/Components/Ranking/types'

export function getRankingStreakType(winStreak: number): RankingStreakType {
    const streak = Number(winStreak) || 0

    if (streak >= 5) {
        return 'purple'
    }

    if (streak >= 4) {
        return 'ice'
    }

    if (streak >= 3) {
        return 'fire'
    }

    return null
}
