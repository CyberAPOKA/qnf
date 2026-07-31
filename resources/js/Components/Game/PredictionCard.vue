<script setup>
import { computed } from 'vue';

const props = defineProps({
    prediction: {
        type: Object,
        default: null,
    },
});

const TEAM_LABELS = {
    green: 'Verde',
    yellow: 'Amarelo',
    blue: 'Azul',
};

const sortedTeams = computed(() => {
    if (!props.prediction?.teams) return [];

    return [...props.prediction.teams].sort((a, b) => b.predicted_score - a.predicted_score);
});

const teamLabel = (team) => TEAM_LABELS[team.color] || team.label;

const clampPercent = (value) => Math.min(Math.max(Number(value) || 0, 0), 100);
</script>

<template>
    <section v-if="prediction" class="pred">
        <div class="pred__inner">
            <span class="pred__corner pred__corner--tl" aria-hidden="true" />
            <span class="pred__corner pred__corner--tr" aria-hidden="true" />
            <span class="pred__corner pred__corner--bl" aria-hidden="true" />
            <span class="pred__corner pred__corner--br" aria-hidden="true" />
            <span class="pred__edge pred__edge--top" aria-hidden="true" />
            <span class="pred__edge pred__edge--bottom" aria-hidden="true" />
            <span class="pred__tab" aria-hidden="true">
                <i v-for="n in 5" :key="n" class="pred__tab-bar" />
            </span>

            <header class="pred__head">
                <span class="pred__emblem" aria-hidden="true">
                    <i class="fa-solid fa-brain" />
                </span>

                <h3 class="pred__title">Previsão IA</h3>

                <span class="pred__strip" aria-hidden="true">
                    <i v-for="n in 18" :key="n" class="pred__strip-bar" />
                </span>
            </header>

            <div class="pred__teams">
                <article v-for="team in sortedTeams" :key="team.color" class="pteam"
                    :class="[`pteam--${team.color}`, { 'pteam--winner': team.color === prediction.predicted_winner }]">
                    <div class="pteam__body">
                        <span class="pteam__corner pteam__corner--tl" aria-hidden="true" />
                        <span class="pteam__corner pteam__corner--tr" aria-hidden="true" />
                        <span class="pteam__corner pteam__corner--bl" aria-hidden="true" />
                        <span class="pteam__corner pteam__corner--br" aria-hidden="true" />
                        <span class="pteam__edge pteam__edge--top" aria-hidden="true" />
                        <span class="pteam__edge pteam__edge--bottom" aria-hidden="true" />

                        <strong class="pteam__score">{{ team.predicted_score }}</strong>

                        <div class="pteam__crest">
                            <span class="pteam__wing pteam__wing--left" aria-hidden="true">
                                <i v-for="n in 3" :key="n" class="pteam__wing-bar" />
                            </span>

                            <span class="pteam__badge" aria-hidden="true">
                                <i class="fa-solid fa-shield-halved" />
                            </span>

                            <span class="pteam__wing pteam__wing--right" aria-hidden="true">
                                <i v-for="n in 3" :key="n" class="pteam__wing-bar" />
                            </span>
                        </div>

                        <div class="pteam__divider" aria-hidden="true" />

                        <div class="pteam__foot">
                            <span class="pteam__dot" aria-hidden="true" />
                            <span class="pteam__label">{{ teamLabel(team) }}</span>
                        </div>
                    </div>

                    <span v-if="team.color === prediction.predicted_winner" class="pteam__fav">
                        <i class="fa-solid fa-trophy" aria-hidden="true" />
                        Favorito
                    </span>
                </article>
            </div>

            <div class="pred__probs">
                <div v-for="team in sortedTeams" :key="`prob-${team.color}`" class="prob"
                    :class="`prob--${team.color}`">
                    <span class="prob__dot" aria-hidden="true" />

                    <div class="prob__track">
                        <div class="prob__fill" :style="{ width: `${clampPercent(team.win_probability)}%` }" />
                    </div>

                    <span class="prob__value">{{ team.win_probability }}%</span>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.pred {
    --cut: 22px;

    position: relative;
    padding: 2px;
    background: linear-gradient(120deg,
        #a855f7 0%,
        rgba(139, 92, 246, 0.55) 22%,
        rgba(30, 41, 59, 0.7) 48%,
        rgba(56, 189, 248, 0.6) 74%,
        #38bdf8 100%);
    filter: drop-shadow(0 0 18px rgba(139, 92, 246, 0.18)) drop-shadow(0 14px 32px rgba(0, 0, 0, 0.5));
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

.pred__inner {
    --cut: 20px;

    position: relative;
    padding: 14px 12px 24px;
    background:
        radial-gradient(80% 60% at 12% 0%, rgba(168, 85, 247, 0.16), transparent 60%),
        radial-gradient(80% 60% at 92% 4%, rgba(56, 189, 248, 0.14), transparent 60%),
        linear-gradient(180deg, #070d1c 0%, #04070f 100%);
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

/* Pontas das bordas do painel */
.pred__corner {
    position: absolute;
    width: 20px;
    height: 20px;
    border: 2px solid #38bdf8;
    filter: drop-shadow(0 0 6px rgba(56, 189, 248, 0.7));
    pointer-events: none;
}

.pred__corner--tl {
    top: 15px;
    left: 5px;
    border-color: #c084fc;
    border-right: 0;
    border-bottom: 0;
    filter: drop-shadow(0 0 6px rgba(192, 132, 252, 0.7));
}

.pred__corner--tr {
    top: 15px;
    right: 5px;
    border-bottom: 0;
    border-left: 0;
}

.pred__corner--bl {
    bottom: 15px;
    left: 5px;
    border-color: #c084fc;
    border-top: 0;
    border-right: 0;
    filter: drop-shadow(0 0 6px rgba(192, 132, 252, 0.7));
}

.pred__corner--br {
    right: 5px;
    bottom: 15px;
    border-top: 0;
    border-left: 0;
}

/* Trapézios no meio das arestas */
.pred__edge {
    position: absolute;
    left: 50%;
    width: 104px;
    height: 6px;
    background: linear-gradient(90deg,
        rgba(56, 189, 248, 0.1),
        #38bdf8 38%,
        #a855f7 62%,
        rgba(168, 85, 247, 0.1));
    transform: translateX(-50%);
    filter: drop-shadow(0 0 7px rgba(56, 189, 248, 0.55));
    pointer-events: none;
}

.pred__edge--top {
    top: 0;
    clip-path: polygon(0 0, 100% 0, calc(100% - 15px) 100%, 15px 100%);
}

.pred__edge--bottom {
    bottom: 0;
    clip-path: polygon(15px 0, calc(100% - 15px) 0, 100% 100%, 0 100%);
}

/* Aba com hachuras no rodapé */
.pred__tab {
    position: absolute;
    bottom: 7px;
    left: 50%;
    display: flex;
    gap: 5px;
    transform: translateX(-50%);
    pointer-events: none;
}

.pred__tab-bar {
    display: block;
    width: 3px;
    height: 9px;
    background: linear-gradient(180deg, #7dd3fc, rgba(56, 189, 248, 0.45));
    transform: skewX(-22deg);
    filter: drop-shadow(0 0 5px rgba(56, 189, 248, 0.6));
}

.pred__head {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 2px 6px 0;
}

.pred__emblem {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 44px;
    background: linear-gradient(180deg, rgba(168, 85, 247, 0.3), rgba(88, 28, 135, 0.2));
    box-shadow: inset 0 0 0 1.5px rgba(192, 132, 252, 0.85);
    color: #d8b4fe;
    font-size: 1.15rem;
    filter: drop-shadow(0 0 8px rgba(168, 85, 247, 0.5));
    clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%);
}

.pred__title {
    flex: 0 1 auto;
    margin: 0;
    color: #f8fafc;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.4rem;
    font-style: italic;
    font-weight: 700;
    letter-spacing: 0.1em;
    line-height: 1;
    text-transform: uppercase;
    white-space: nowrap;
    text-shadow: 0 0 14px rgba(148, 163, 184, 0.35);
}

/* Faixa de dados decorativa */
.pred__strip {
    flex: 1 1 auto;
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
    gap: 4px;
    height: 18px;
    overflow: hidden;
    opacity: 0.75;
}

.pred__strip-bar {
    display: block;
    width: 3px;
    height: 8px;
    background: rgba(56, 189, 248, 0.55);
    transform: skewX(-18deg);
}

.pred__strip-bar:nth-child(3n) {
    height: 14px;
    background: rgba(56, 189, 248, 0.8);
}

.pred__strip-bar:nth-child(4n) {
    height: 5px;
    background: rgba(56, 189, 248, 0.35);
}

.pred__teams {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: start;
    gap: 8px;
    margin-top: 14px;
}

.pteam {
    --team: #22c55e;
    --team-rgb: 34, 197, 94;
    --team-light: #86efac;

    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.pteam--green {
    --team: #22c55e;
    --team-rgb: 34, 197, 94;
    --team-light: #86efac;
}

.pteam--yellow {
    --team: #eab308;
    --team-rgb: 234, 179, 8;
    --team-light: #fde68a;
}

.pteam--blue {
    --team: #3b82f6;
    --team-rgb: 59, 130, 246;
    --team-light: #93c5fd;
}

.pteam__body {
    --cut: 14px;

    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    padding: 12px 6px 0;
    background:
        radial-gradient(rgba(var(--team-rgb), 0.14) 1px, transparent 1px) 0 0 / 7px 7px,
        radial-gradient(120% 70% at 50% 0%, rgba(var(--team-rgb), 0.22), transparent 62%),
        linear-gradient(180deg, rgba(9, 15, 28, 0.96) 0%, rgba(5, 8, 16, 0.98) 100%);
    box-shadow:
        inset 0 0 0 1.5px rgba(var(--team-rgb), 0.6),
        inset 0 0 30px rgba(var(--team-rgb), 0.12);
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

.pteam--winner .pteam__body {
    box-shadow:
        inset 0 0 0 2px rgba(var(--team-rgb), 0.95),
        inset 0 0 34px rgba(var(--team-rgb), 0.2),
        0 0 20px rgba(var(--team-rgb), 0.3);
}

/* Pontas das bordas do card do time */
.pteam__corner {
    position: absolute;
    z-index: 2;
    width: 13px;
    height: 13px;
    border: 2px solid var(--team);
    filter: drop-shadow(0 0 5px rgba(var(--team-rgb), 0.7));
    pointer-events: none;
}

.pteam__corner--tl {
    top: 9px;
    left: 1px;
    border-right: 0;
    border-bottom: 0;
}

.pteam__corner--tr {
    top: 9px;
    right: 1px;
    border-bottom: 0;
    border-left: 0;
}

.pteam__corner--bl {
    bottom: 9px;
    left: 1px;
    border-top: 0;
    border-right: 0;
}

.pteam__corner--br {
    right: 1px;
    bottom: 9px;
    border-top: 0;
    border-left: 0;
}

/* Marcadores no meio das arestas do card */
.pteam__edge {
    position: absolute;
    left: 50%;
    z-index: 2;
    width: 38px;
    height: 4px;
    background: linear-gradient(90deg, transparent, var(--team), transparent);
    transform: translateX(-50%);
    filter: drop-shadow(0 0 5px rgba(var(--team-rgb), 0.6));
    pointer-events: none;
}

.pteam__edge--top {
    top: 0;
    clip-path: polygon(0 0, 100% 0, calc(100% - 8px) 100%, 8px 100%);
}

.pteam__edge--bottom {
    bottom: 0;
    clip-path: polygon(8px 0, calc(100% - 8px) 0, 100% 100%, 0 100%);
}

.pteam__score {
    position: relative;
    z-index: 1;
    display: block;
    color: var(--team-light);
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 2.1rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    line-height: 1;
    transform: skewX(-8deg);
    text-shadow:
        0 0 10px rgba(var(--team-rgb), 0.85),
        0 0 26px rgba(var(--team-rgb), 0.5);
}

.pteam__crest {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    margin-top: 10px;
}

.pteam__badge {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: radial-gradient(circle at 50% 30%, rgba(var(--team-rgb), 0.35), rgba(5, 8, 16, 0.9) 70%);
    box-shadow:
        inset 0 0 0 1.5px rgba(var(--team-rgb), 0.9),
        0 0 12px rgba(var(--team-rgb), 0.35);
    color: var(--team-light);
    font-size: 0.85rem;
}

.pteam__wing {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 2px;
}

.pteam__wing-bar {
    display: block;
    width: 7px;
    height: 2px;
    background: var(--team);
    transform: skewX(-30deg);
    opacity: 0.55;
}

.pteam__wing-bar:nth-child(2) {
    width: 10px;
    opacity: 0.75;
}

.pteam__wing-bar:nth-child(3) {
    width: 13px;
    opacity: 0.95;
}

.pteam__wing--right .pteam__wing-bar:nth-child(1) {
    width: 13px;
    opacity: 0.95;
}

.pteam__wing--right .pteam__wing-bar:nth-child(3) {
    width: 7px;
    opacity: 0.55;
}

.pteam__divider {
    width: calc(100% + 12px);
    height: 1px;
    margin: 10px -6px 0;
    background: linear-gradient(90deg, transparent, rgba(var(--team-rgb), 0.65), transparent);
}

.pteam__foot {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: calc(100% + 12px);
    margin: 0 -6px;
    padding: 8px 4px 9px;
    background: linear-gradient(180deg, rgba(var(--team-rgb), 0.1), rgba(var(--team-rgb), 0.04));
}

.pteam__dot {
    flex: 0 0 auto;
    width: 9px;
    height: 9px;
    border-radius: 999px;
    background: var(--team);
    box-shadow: 0 0 8px rgba(var(--team-rgb), 0.9);
}

.pteam__label {
    min-width: 0;
    color: #e2e8f0;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Selo do favorito */
.pteam__fav {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 8px;
    padding: 4px 12px;
    background: linear-gradient(180deg, rgba(168, 85, 247, 0.28), rgba(88, 28, 135, 0.18));
    box-shadow: inset 0 0 0 1.5px rgba(192, 132, 252, 0.8);
    color: #e9d5ff;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    white-space: nowrap;
    filter: drop-shadow(0 0 8px rgba(168, 85, 247, 0.35));
    clip-path: polygon(8px 0, calc(100% - 8px) 0, 100% 50%, calc(100% - 8px) 100%, 8px 100%, 0 50%);
}

.pteam__fav i {
    color: #f0abfc;
    font-size: 0.7rem;
}

/* Painel de probabilidades */
.pred__probs {
    --cut: 12px;

    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 16px;
    padding: 12px 10px;
    background: linear-gradient(180deg, rgba(11, 18, 34, 0.9), rgba(5, 8, 16, 0.92));
    box-shadow: inset 0 0 0 1px rgba(56, 189, 248, 0.28);
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

.prob {
    --team: #22c55e;
    --team-rgb: 34, 197, 94;
    --team-light: #86efac;

    display: flex;
    align-items: center;
    gap: 10px;
}

.prob--green {
    --team: #22c55e;
    --team-rgb: 34, 197, 94;
    --team-light: #86efac;
}

.prob--yellow {
    --team: #eab308;
    --team-rgb: 234, 179, 8;
    --team-light: #fde68a;
}

.prob--blue {
    --team: #3b82f6;
    --team-rgb: 59, 130, 246;
    --team-light: #93c5fd;
}

.prob__dot {
    flex: 0 0 auto;
    width: 14px;
    height: 14px;
    border-radius: 999px;
    background: radial-gradient(circle at 35% 30%, var(--team-light), var(--team) 70%);
    box-shadow: 0 0 10px rgba(var(--team-rgb), 0.75);
}

.prob__track {
    flex: 1 1 auto;
    height: 14px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.16);
    box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.18);
    overflow: hidden;
}

.prob__fill {
    height: 100%;
    border-radius: 999px;
    background:
        repeating-linear-gradient(
            115deg,
            rgba(255, 255, 255, 0.22) 0 5px,
            transparent 5px 11px
        ),
        linear-gradient(180deg, var(--team-light), var(--team));
    box-shadow:
        0 0 12px rgba(var(--team-rgb), 0.6),
        inset -2px 0 0 rgba(255, 255, 255, 0.5);
    transition: width 500ms ease;
}

.prob__value {
    flex: 0 0 auto;
    min-width: 56px;
    color: var(--team-light);
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.9rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    text-align: right;
    text-shadow: 0 0 10px rgba(var(--team-rgb), 0.5);
}

@media (min-width: 640px) {
    .pred__inner {
        padding: 18px 18px 28px;
    }

    .pred__title {
        font-size: 1.7rem;
    }

    .pred__emblem {
        width: 46px;
        height: 50px;
        font-size: 1.35rem;
    }

    .pred__teams {
        gap: 14px;
        margin-top: 18px;
    }

    .pteam__body {
        --cut: 16px;

        padding: 18px 10px 0;
    }

    .pteam__score {
        font-size: 3.1rem;
    }

    .pteam__badge {
        width: 44px;
        height: 44px;
        font-size: 1.05rem;
    }

    .pteam__label {
        font-size: 1.05rem;
    }

    .pteam__edge {
        width: 54px;
    }

    .prob__track,
    .prob__dot {
        height: 16px;
    }

    .prob__dot {
        width: 16px;
    }

    .prob__value {
        font-size: 1rem;
        min-width: 64px;
    }
}
</style>
