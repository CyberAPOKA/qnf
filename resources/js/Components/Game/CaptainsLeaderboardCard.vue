<script setup>
defineProps({
    captains: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <article class="captains-board">
        <header class="captains-board__head">
            <div class="flex gap-2">
                <h2 class="captains-board__title">Capitães</h2>
                <i class="fa-solid fa-crown text-lg text-white" aria-hidden="true" />
            </div>
            <p class="captains-board__subtitle">
                <template v-if="captains.length">
                    Top {{ captains.length }}
                    {{ captains.length === 1 ? 'jogador' : 'jogadores' }}
                    com mais rodadas de capitão
                </template>
                <template v-else>
                    Nenhum capitão registrado ainda
                </template>
            </p>
        </header>

        <div class="captains-board__divider" aria-hidden="true" />

        <ol v-if="captains.length" class="captains-board__list">
            <li
                v-for="(captain, index) in captains"
                :key="captain.id"
                class="captain-row"
                :class="{ 'captain-row--lead': index === 0 }"
            >
                <span class="captain-row__rank" :class="`captain-row__rank--${index + 1}`">
                    {{ index + 1 }}
                </span>

                <div class="captain-row__body">
                    <div class="captain-row__identity">
                        <span class="captain-row__name">{{ captain.name }}</span>
                        <span class="captain-row__count">
                            {{ captain.count }}x
                        </span>
                    </div>

                    <p class="captain-row__rounds">
                        <span
                            v-for="round in captain.rounds"
                            :key="round"
                            class="captain-row__chip"
                        >
                            {{ round }}
                        </span>
                    </p>
                </div>
            </li>
        </ol>

        <p v-else class="captains-board__empty">
            Os capitães aparecem aqui depois que os times forem formados.
        </p>
    </article>
</template>

<style scoped>
.captains-board {
    --cut: 16px;
    --frame: 1.6px;
    --inner-cut: calc(var(--cut) - var(--frame));

    position: relative;
    margin-bottom: 10px;
    padding: 14px 12px 12px;
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

.captains-board::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -2;
    background: linear-gradient(135deg, rgba(56, 189, 248, 0.9), rgba(139, 92, 246, 0.85));
}

.captains-board::after {
    content: '';
    position: absolute;
    inset: var(--frame);
    z-index: -1;
    background:
        radial-gradient(ellipse at top, rgba(250, 204, 21, 0.08), transparent 55%),
        linear-gradient(180deg, rgba(10, 17, 32, 0.97) 0%, rgba(5, 9, 18, 0.98) 100%);
    clip-path: polygon(
        var(--inner-cut) 0,
        calc(100% - var(--inner-cut)) 0,
        100% var(--inner-cut),
        100% calc(100% - var(--inner-cut)),
        calc(100% - var(--inner-cut)) 100%,
        var(--inner-cut) 100%,
        0 calc(100% - var(--inner-cut)),
        0 var(--inner-cut)
    );
}

.captains-board__head {
    padding: 2px 6px 0;
}

.captains-board__title {
    margin: 0;
    color: #f8fafc;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    line-height: 1.1;
}

.captains-board__subtitle {
    margin: 4px 0 0;
    color: #94a3b8;
    font-size: 0.78rem;
    font-weight: 600;
}

.captains-board__divider {
    height: 1px;
    margin: 10px 2px 12px;
    background: linear-gradient(90deg, transparent, rgba(250, 204, 21, 0.4), transparent);
}

.captains-board__list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.captain-row {
    --cut: 10px;

    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 9px 10px;
    background: rgba(2, 6, 23, 0.55);
    box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.16);
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

.captain-row--lead {
    background: rgba(250, 204, 21, 0.08);
    box-shadow: inset 0 0 0 1px rgba(250, 204, 21, 0.28);
}

.captain-row__rank {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    margin-top: 1px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.9);
    box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.28);
    color: #cbd5e1;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.72rem;
    font-weight: 700;
}

.captain-row__rank--1 {
    background: rgba(250, 204, 21, 0.18);
    box-shadow: inset 0 0 0 1px rgba(250, 204, 21, 0.55);
    color: #facc15;
}

.captain-row__rank--2 {
    background: rgba(148, 163, 184, 0.16);
    box-shadow: inset 0 0 0 1px rgba(203, 213, 225, 0.5);
    color: #e2e8f0;
}

.captain-row__rank--3 {
    background: rgba(217, 119, 6, 0.18);
    box-shadow: inset 0 0 0 1px rgba(251, 146, 60, 0.5);
    color: #fb923c;
}

.captain-row__body {
    display: flex;
    flex: 1 1 auto;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 6px 12px;
    min-width: 0;
}

.captain-row__identity {
    display: flex;
    align-items: baseline;
    gap: 8px;
    min-width: 0;
}

.captain-row__name {
    color: #f8fafc;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.98rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    line-height: 1.15;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.captain-row__count {
    flex-shrink: 0;
    color: #facc15;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
}

.captain-row__rounds {
    display: flex;
    flex: 1 1 auto;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 4px;
    margin: 0;
}

.captain-row__chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    padding: 2px 7px;
    border: 1px solid rgba(56, 189, 248, 0.35);
    border-radius: 6px;
    background: rgba(56, 189, 248, 0.1);
    color: #7dd3fc;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.68rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.captains-board__empty {
    margin: 0 4px;
    padding: 16px 8px;
    color: #94a3b8;
    font-size: 0.82rem;
    font-weight: 600;
    text-align: center;
}

@media (max-width: 480px) {
    .captain-row__body {
        flex-direction: column;
        align-items: stretch;
        gap: 6px;
    }

    .captain-row__rounds {
        justify-content: flex-start;
    }
}
</style>
