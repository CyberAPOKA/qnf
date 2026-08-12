<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import TeamsBoard from '@/Components/Game/TeamsBoard.vue';
import TeamColorStatsCard from '@/Components/Game/TeamColorStatsCard.vue';

const props = defineProps({
    rounds: {
        type: Array,
        default: () => [],
    },
    current_user_id: Number,
});

const formatDate = (date) => {
    if (!date) return '';

    const parsed = new Date(date);
    if (Number.isNaN(parsed.getTime())) return '';

    return parsed.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const roundScores = (teams) => {
    if (!teams) return null;

    const scores = ['green', 'yellow', 'blue']
        .map((color) => teams[color]?.score)
        .filter((score) => score != null);

    return scores.length ? scores.join(' · ') : null;
};

const hasRounds = computed(() => props.rounds.length > 0);

const playerResult = (teams) => {
    if (!teams) return null;

    const colors = ['green', 'yellow', 'blue'];
    let playerColor = null;

    for (const color of colors) {
        const team = teams[color];
        if (!team) continue;
        if (team.captain?.id === props.current_user_id) { playerColor = color; break; }
        if (team.players?.some((p) => p.id === props.current_user_id)) { playerColor = color; break; }
    }

    if (!playerColor) return null;

    const playerScore = teams[playerColor]?.score;
    if (playerScore == null) return null;

    const otherScores = colors
        .filter((c) => c !== playerColor)
        .map((c) => teams[c]?.score)
        .filter((s) => s != null);

    if (!otherScores.length) return null;

    const maxOther = Math.max(...otherScores);

    if (playerScore > maxOther) return 'win';
    if (playerScore === maxOther) return 'draw';
    return 'loss';
};

const resultConfig = {
    win: { label: 'Vitória' },
    draw: { label: 'Empate' },
    loss: { label: 'Derrota' },
};
</script>

<template>
    <AppLayout title="Rodadas">
        <template #header>
            <TitleCard />
        </template>

        <div class="px-1 py-3 pb-24 sm:px-4 lg:px-8 lg:py-6">
            <div class="mx-auto max-w-3xl space-y-2">
                <TeamColorStatsCard :rounds="rounds" />

                <p v-if="!hasRounds" class="rounds__empty">
                    Nenhuma rodada com times formados ainda.
                </p>

                <article v-for="round in rounds" :key="round.round" class="round-panel">
                    <span class="round-panel__corner round-panel__corner--tl" aria-hidden="true" />
                    <span class="round-panel__corner round-panel__corner--tr" aria-hidden="true" />
                    <span class="round-panel__corner round-panel__corner--bl" aria-hidden="true" />
                    <span class="round-panel__corner round-panel__corner--br" aria-hidden="true" />

                    <header class="round-panel__head">
                        <div class="round-panel__identity">
                            <h2 class="round-panel__title">
                                Rodada {{ round.round }}
                                <span v-if="playerResult(round.teams)" class="round-panel__result"
                                    :class="`round-panel__result--${playerResult(round.teams)}`">
                                    {{ resultConfig[playerResult(round.teams)].label }}
                                </span>
                                <p v-if="round.date" class="round-panel__date">{{ formatDate(round.date) }}</p>
                            </h2>
                        </div>

                        <!-- <div class="round-panel__meta">
                            <span v-if="roundScores(round.teams)" class="round-panel__scores">
                                {{ roundScores(round.teams) }}
                            </span>
                            <span class="round-panel__status">{{ round.status_label }}</span>
                        </div> -->
                    </header>

                    <div class="round-panel__divider" aria-hidden="true" />

                    <TeamsBoard :teams="round.teams" :show-header="false" flush />
                </article>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.rounds__empty {
    --cut: 14px;

    margin: 0;
    padding: 32px 16px;
    background: rgba(8, 14, 28, 0.7);
    box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.25);
    color: #94a3b8;
    font-size: 0.9rem;
    font-weight: 600;
    text-align: center;
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
}

.round-panel {
    --cut: 16px;
    --frame: 1.6px;

    position: relative;
    padding: 10px 8px 6px;
    background: transparent;
    filter: drop-shadow(0 0 18px rgba(56, 189, 248, 0.12)) drop-shadow(0 12px 26px rgba(0, 0, 0, 0.45));
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
}

/* Moldura em gradiente ciano → roxo com cantos chanfrados */
.round-panel::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -2;
    background: linear-gradient(135deg, rgba(56, 189, 248, 0.9), rgba(139, 92, 246, 0.85));
}

.round-panel::after {
    content: '';
    position: absolute;
    inset: var(--frame);
    z-index: -1;
    background:
        radial-gradient(ellipse at top, rgba(56, 189, 248, 0.08), transparent 55%),
        linear-gradient(180deg, rgba(10, 17, 32, 0.97) 0%, rgba(5, 9, 18, 0.98) 100%);
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
}

.round-panel__corner {
    position: absolute;
    z-index: 2;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(56, 189, 248, 0.9);
    pointer-events: none;
}

.round-panel__corner--tl {
    top: 12px;
    left: 12px;
    border-right: 0;
    border-bottom: 0;
}

.round-panel__corner--tr {
    top: 12px;
    right: 12px;
    border-bottom: 0;
    border-left: 0;
}

.round-panel__corner--bl {
    bottom: 12px;
    left: 12px;
    border-top: 0;
    border-right: 0;
}

.round-panel__corner--br {
    right: 12px;
    bottom: 12px;
    border-top: 0;
    border-left: 0;
}

.round-panel__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 4px 6px 0;
}

.round-panel__identity {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-width: 0;
}

.round-panel__title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: #f8fafc;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.4rem;
    font-weight: 800;
    letter-spacing: 0.01em;
    line-height: 1.1;
}

.round-panel__result {
    --tone: #94a3b8;
    --tone-rgb: 148, 163, 184;

    display: inline-flex;
    align-items: center;
    padding: 2px 9px;
    border: 1px solid rgba(var(--tone-rgb), 0.45);
    border-radius: 6px;
    background: rgba(var(--tone-rgb), 0.14);
    color: var(--tone);
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.04em;
}

.round-panel__result--win {
    --tone: #4ade80;
    --tone-rgb: 74, 222, 128;
}

.round-panel__result--draw {
    --tone: #facc15;
    --tone-rgb: 250, 204, 21;
}

.round-panel__result--loss {
    --tone: #f87171;
    --tone-rgb: 248, 113, 113;
}

.round-panel__date {
    margin: 4px 0 0;
    color: #a2a4a7;
    font-size: 0.8rem;
    font-weight: 600;
}

.round-panel__meta {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    gap: 10px;
}

.round-panel__scores {
    color: #e2e8f0;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.95rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.round-panel__status {
    padding: 4px 12px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.8);
    color: #cbd5e1;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    white-space: nowrap;
}

.round-panel__divider {
    height: 1px;
    margin: 6px 2px 8px;
    background: linear-gradient(90deg, transparent, rgba(56, 189, 248, 0.35), transparent);
}

@media (max-width: 480px) {
    .round-panel__head {
        flex-direction: column;
        gap: 8px;
    }

    .round-panel__meta {
        align-self: stretch;
        justify-content: space-between;
    }
}
</style>
