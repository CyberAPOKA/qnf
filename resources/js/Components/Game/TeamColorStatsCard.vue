<script setup>
import { computed } from 'vue';

const props = defineProps({
    rounds: {
        type: Array,
        default: () => [],
    },
});

const COLORS = ['green', 'yellow', 'blue'];

const COLOR_META = {
    green: { label: 'Verde' },
    yellow: { label: 'Amarelo' },
    blue: { label: 'Azul' },
};

const emptyTeam = () => ({
    points: 0,
    uniqueWins: 0,
    doubleTies: 0,
    losses: 0,
    tripleTies: 0,
});

const scoredRounds = computed(() =>
    props.rounds.filter((round) => {
        const teams = round?.teams;
        if (!teams) return false;

        return COLORS.every((color) => teams[color]?.score != null);
    }),
);

const stats = computed(() => {
    const byColor = Object.fromEntries(COLORS.map((color) => [color, emptyTeam()]));
    let scoredCount = 0;

    for (const round of scoredRounds.value) {
        const scores = Object.fromEntries(
            COLORS.map((color) => [color, Number(round.teams[color].score)]),
        );

        const values = Object.values(scores);
        const max = Math.max(...values);
        const leaders = COLORS.filter((color) => scores[color] === max);

        scoredCount += 1;

        if (leaders.length === 3) {
            for (const color of COLORS) {
                byColor[color].tripleTies += 1;
            }
            continue;
        }

        if (leaders.length === 2) {
            for (const color of COLORS) {
                if (leaders.includes(color)) {
                    byColor[color].doubleTies += 1;
                    byColor[color].points += 1;
                } else {
                    byColor[color].losses += 1;
                }
            }
            continue;
        }

        // Vitória única
        for (const color of COLORS) {
            if (leaders.includes(color)) {
                byColor[color].uniqueWins += 1;
                byColor[color].points += 1;
            } else {
                byColor[color].losses += 1;
            }
        }
    }

    const totalPoints = COLORS.reduce((sum, color) => sum + byColor[color].points, 0);

    const teams = COLORS.map((color) => {
        const team = byColor[color];
        const aproveitamento = scoredCount
            ? ((team.uniqueWins + team.doubleTies) / scoredCount) * 100
            : null;
        const pointsShare = totalPoints
            ? (team.points / totalPoints) * 100
            : null;

        return {
            color,
            ...COLOR_META[color],
            ...team,
            aproveitamento,
            pointsShare,
        };
    });

    const maxPoints = Math.max(...teams.map((team) => team.points), 0);
    const leaders = teams.filter((team) => team.points === maxPoints && maxPoints > 0);

    return {
        scoredCount,
        totalPoints,
        teams,
        leaders,
        isBalanced: leaders.length >= 2 || maxPoints === 0,
    };
});

const formatPct = (value) => {
    if (value == null) return '—';
    return `${value.toFixed(0)}%`;
};

const insight = computed(() => {
    const { scoredCount, leaders, isBalanced, teams } = stats.value;

    if (!scoredCount) {
        return 'Aguardando placares para medir o equilíbrio entre as cores.';
    }

    if (isBalanced && leaders.length >= 2) {
        const names = leaders.map((team) => team.label).join(' e ');
        return `${names} empatados na liderança — draft equilibrado.`;
    }

    if (leaders.length === 1) {
        const leader = leaders[0];
        const lagging = [...teams]
            .sort((a, b) => a.points - b.points)[0];
        const gap = leader.points - lagging.points;

        return `${leader.label} lidera com ${leader.points} pts (${formatPct(leader.aproveitamento)} apr.). ${lagging.label} atrás por ${gap} — priorize no draft.`;
    }

    return 'Sem vantagem clara entre as cores.';
});
</script>

<template>
    <article class="color-stats">
        <header class="color-stats__head">
            <div class="color-stats__titles">
                <div class="flex gap-2">
                    <h2 class="color-stats__title">Equilíbrio dos times</h2>
                    <i class="fa-solid fa-scale-balanced text-lg text-white" aria-hidden="true" />
                </div>

                <p class="color-stats__subtitle">
                    <template v-if="stats.scoredCount">
                        {{ stats.scoredCount }}
                        {{ stats.scoredCount === 1 ? 'rodada' : 'rodadas' }}
                        com placar · {{ stats.totalPoints }}
                        {{ stats.totalPoints === 1 ? 'ponto' : 'pontos' }} distribuídos
                    </template>
                    <template v-else>
                        Nenhuma rodada com placar ainda
                    </template>
                </p>
            </div>
        </header>

        <div class="color-stats__divider" aria-hidden="true" />

        <div class="color-stats__grid">
            <section v-for="team in stats.teams" :key="team.color" class="cteam" :class="[
                `cteam--${team.color}`,
                { 'cteam--lead': stats.leaders.some((l) => l.color === team.color) && !stats.isBalanced },
            ]">
                <p class="cteam__label">{{ team.label }}</p>
                <p class="cteam__points">
                    <strong>{{ team.points }}</strong>
                    <span>pts</span>
                </p>

                <div class="cteam__pcts" aria-label="Percentuais">
                    <div class="cteam__pct">
                        <span class="cteam__pct-value">{{ formatPct(team.aproveitamento) }}</span>
                        <span class="cteam__pct-label">apr.</span>
                    </div>
                    <div class="cteam__pct">
                        <span class="cteam__pct-value">{{ formatPct(team.pointsShare) }}</span>
                        <span class="cteam__pct-label">share</span>
                    </div>
                </div>

                <ul class="cteam__breakdown">
                    <li class="cteam__stat cteam__stat--win" title="Vitória única">
                        <span class="cteam__stat-key">V</span>
                        <strong>{{ team.uniqueWins }}</strong>
                    </li>
                    <li class="cteam__stat cteam__stat--tie2" title="Empate duplo">
                        <span class="cteam__stat-key">E2</span>
                        <strong>{{ team.doubleTies }}</strong>
                    </li>
                    <li class="cteam__stat cteam__stat--loss" title="Derrota">
                        <span class="cteam__stat-key">D</span>
                        <strong>{{ team.losses }}</strong>
                    </li>
                    <li class="cteam__stat cteam__stat--tie3" title="Empate triplo (sem ponto)">
                        <span class="cteam__stat-key">E3</span>
                        <strong>{{ team.tripleTies }}</strong>
                    </li>
                </ul>
            </section>
        </div>

        <p class="color-stats__legend">
            <span><strong>V</strong> vitória única</span>
            <span><strong>E2</strong> empate duplo</span>
            <span><strong>D</strong> derrota</span>
            <span><strong>E3</strong> empate triplo (0 pt)</span>
        </p>

        <!-- <p class="color-stats__insight">
            <i class="fa-solid fa-scale-balanced" aria-hidden="true" />
            {{ insight }}
        </p> -->
    </article>
</template>

<style scoped>
.color-stats {
    --cut: 16px;
    --frame: 1.6px;
    --inner-cut: calc(var(--cut) - var(--frame));

    position: relative;
    margin-bottom: 10px;
    padding: 14px 12px 12px;
    background: transparent;
    filter: drop-shadow(0 0 18px rgba(56, 189, 248, 0.12)) drop-shadow(0 12px 26px rgba(0, 0, 0, 0.45));
    clip-path: polygon(var(--cut) 0,
            calc(100% - var(--cut)) 0,
            100% var(--cut),
            100% calc(100% - var(--cut)),
            calc(100% - var(--cut)) 100%,
            var(--cut) 100%,
            0 calc(100% - var(--cut)),
            0 var(--cut));
}

.color-stats::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -2;
    background: linear-gradient(135deg, rgba(56, 189, 248, 0.9), rgba(139, 92, 246, 0.85));
}

.color-stats::after {
    content: '';
    position: absolute;
    inset: var(--frame);
    z-index: -1;
    background:
        radial-gradient(ellipse at top, rgba(56, 189, 248, 0.1), transparent 55%),
        linear-gradient(180deg, rgba(10, 17, 32, 0.97) 0%, rgba(5, 9, 18, 0.98) 100%);
    clip-path: polygon(var(--inner-cut) 0,
            calc(100% - var(--inner-cut)) 0,
            100% var(--inner-cut),
            100% calc(100% - var(--inner-cut)),
            calc(100% - var(--inner-cut)) 100%,
            var(--inner-cut) 100%,
            0 calc(100% - var(--inner-cut)),
            0 var(--inner-cut));
}

.color-stats__head {
    padding: 2px 6px 0;
}

.color-stats__title {
    margin: 0;
    color: #f8fafc;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    line-height: 1.1;
}

.color-stats__subtitle {
    margin: 4px 0 0;
    color: #94a3b8;
    font-size: 0.78rem;
    font-weight: 600;
}

.color-stats__divider {
    height: 1px;
    margin: 10px 2px 12px;
    background: linear-gradient(90deg, transparent, rgba(56, 189, 248, 0.35), transparent);
}

.color-stats__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}

.cteam {
    --team: #22c55e;
    --team-rgb: 34, 197, 94;
    --cut: 12px;
    --frame: 1.5px;
    --inner-cut: calc(var(--cut) - var(--frame));

    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 12px 6px 10px;
    background: transparent;
    filter: drop-shadow(0 0 10px rgba(var(--team-rgb), 0.18));
    clip-path: polygon(var(--cut) 0,
            calc(100% - var(--cut)) 0,
            100% var(--cut),
            100% calc(100% - var(--cut)),
            calc(100% - var(--cut)) 100%,
            var(--cut) 100%,
            0 calc(100% - var(--cut)),
            0 var(--cut));
}

.cteam::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -2;
    background: rgba(var(--team-rgb), 0.75);
}

.cteam::after {
    content: '';
    position: absolute;
    inset: var(--frame);
    z-index: -1;
    background:
        radial-gradient(120% 70% at 50% 0%, rgba(var(--team-rgb), 0.22), transparent 62%),
        linear-gradient(180deg, rgba(9, 15, 28, 0.96) 0%, rgba(5, 8, 16, 0.98) 100%);
    box-shadow: inset 0 0 28px rgba(var(--team-rgb), 0.1);
    clip-path: polygon(var(--inner-cut) 0,
            calc(100% - var(--inner-cut)) 0,
            100% var(--inner-cut),
            100% calc(100% - var(--inner-cut)),
            calc(100% - var(--inner-cut)) 100%,
            var(--inner-cut) 100%,
            0 calc(100% - var(--inner-cut)),
            0 var(--inner-cut));
}

.cteam--green {
    --team: #22c55e;
    --team-rgb: 34, 197, 94;
}

.cteam--yellow {
    --team: #eab308;
    --team-rgb: 234, 179, 8;
}

.cteam--blue {
    --team: #3b82f6;
    --team-rgb: 59, 130, 246;
}

.cteam--lead {
    --frame: 2px;
    filter: drop-shadow(0 0 14px rgba(var(--team-rgb), 0.35));
}

.cteam--lead::before {
    background: rgba(var(--team-rgb), 0.95);
}

.cteam--lead::after {
    box-shadow: inset 0 0 34px rgba(var(--team-rgb), 0.2);
}

.cteam__label {
    margin: 0;
    color: var(--team);
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.85rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.cteam__points {
    display: flex;
    align-items: baseline;
    gap: 4px;
    margin: 0;
    color: #f8fafc;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    line-height: 1;
}

.cteam__points strong {
    font-size: 1.55rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.cteam__points span {
    color: #94a3b8;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.cteam__pcts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
    width: 100%;
    margin-top: 2px;
}

.cteam__pct {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 4px 2px;
    border-radius: 6px;
    background: rgba(15, 23, 42, 0.65);
    box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.15);
}

.cteam__pct-value {
    color: #e2e8f0;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.78rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.cteam__pct-label {
    color: #64748b;
    font-size: 0.58rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.cteam__breakdown {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
    width: 100%;
    margin: 4px 0 0;
    padding: 0;
    list-style: none;
}

.cteam__stat {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
    padding: 3px 6px;
    border-radius: 5px;
    background: rgba(2, 6, 23, 0.55);
    color: #cbd5e1;
    font-size: 0.72rem;
}

.cteam__stat-key {
    color: #64748b;
    font-weight: 800;
    letter-spacing: 0.04em;
}

.cteam__stat strong {
    font-variant-numeric: tabular-nums;
    font-weight: 800;
}

.cteam__stat--win strong {
    color: #4ade80;
}

.cteam__stat--tie2 strong {
    color: #facc15;
}

.cteam__stat--loss strong {
    color: #f87171;
}

.cteam__stat--tie3 strong {
    color: #94a3b8;
}

.color-stats__legend {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px 14px;
    margin: 12px 4px 0;
    color: #d5d6d8;
    font-size: 0.68rem;
    font-weight: 600;
}

.color-stats__legend strong {
    color: #ffffff;
    font-weight: 800;
}

.color-stats__insight {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 10px 2px 0;
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(56, 189, 248, 0.08);
    box-shadow: inset 0 0 0 1px rgba(56, 189, 248, 0.22);
    color: #cbd5e1;
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1.35;
}

.color-stats__insight i {
    margin-top: 2px;
    color: #38bdf8;
    flex-shrink: 0;
}

@media (max-width: 420px) {
    .color-stats__grid {
        gap: 6px;
    }

    .cteam__points strong {
        font-size: 1.3rem;
    }

    .cteam__pct-value {
        font-size: 0.7rem;
    }

    .cteam__stat {
        padding: 3px 4px;
        font-size: 0.68rem;
    }

    .color-stats__legend {
        gap: 6px 10px;
        font-size: 0.62rem;
    }
}
</style>
