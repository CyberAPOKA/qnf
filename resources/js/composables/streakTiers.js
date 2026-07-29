/**
 * Streak visual tiers — ordered highest-first.
 * Add a new tier here and wire rowClass / icons; RankingCard + particles pick it up.
 */
export const STREAK_TIERS = [
    {
        id: 'legendary',
        min: 5,
        rowClass: 'qnf-streak-row qnf-streak--legendary',
        particleSelector: '.qnf-streak--legendary',
        particleColors: ['#6d28d9', '#7c3aed', '#a855f7', '#c084fc', '#e9d5ff', '#d946ef'],
        icon: 'crown',
    },
    {
        id: 'hot',
        min: 3,
        rowClass: 'qnf-streak-row qnf-streak--hot',
        particleSelector: '.qnf-streak--hot',
        particleColors: ['#ff3b00', '#ff6a00', '#ff9500', '#ffcc00', '#ffee00', '#fff2a0'],
        icon: 'fire',
    },
];

export function resolveStreakTier(winStreak) {
    const streak = Number(winStreak) || 0;
    return STREAK_TIERS.find((tier) => streak >= tier.min) ?? null;
}

export function streakRowClass(winStreak) {
    return resolveStreakTier(winStreak)?.rowClass ?? '';
}

/** Targets for useFireParticles.init — particles + iOS-safe aura overlay. */
export function streakParticleTargets() {
    return STREAK_TIERS.map((tier) => ({
        selector: tier.particleSelector,
        colors: tier.particleColors,
        auraClass: `qnf-streak-aura qnf-streak-aura--${tier.id}`,
    }));
}
