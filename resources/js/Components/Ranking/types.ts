export type PlayerForm = 'win' | 'draw' | 'loss'

export type RankingTheme = 'gold' | 'silver' | 'bronze' | 'default'

export type RankingStreakType = 'fire' | 'ice' | 'purple' | 'skulls' | 'dragon' | null

export interface RankingPlayer {
    rank: number
    name: string
    photo_front?: string | null
    initial?: string
    points: number
    games: number
    movement: number | null
    form: PlayerForm[]
    theme: RankingTheme
    win_streak: number
    /** Optional label for the points column (default: PTS) */
    pointsLabel?: string
    /** Optional average metric (e.g. wins ranking) */
    avg?: number | null
    /** Show goalkeeper glove next to the name */
    isGoalkeeper?: boolean
}

export const MEDAL_ICONS: Partial<Record<RankingTheme, string>> = {
    gold: '/assets/svgs/gold.webp',
    silver: '/assets/svgs/silver.webp',
    bronze: '/assets/svgs/bronze.webp',
}

export const THEME_CLASS: Record<RankingTheme, string> = {
    gold: 'ranking-card--gold',
    silver: 'ranking-card--silver',
    bronze: 'ranking-card--bronze',
    default: 'ranking-card--default',
}
