<script setup>
import { computed } from 'vue';
import TeamCard from '@/Components/Game/TeamCard.vue';

const props = defineProps({
    teams: {
        type: Object,
        default: null,
    },
    title: {
        type: String,
        default: 'Placar Final',
    },
    statusLabel: {
        type: String,
        default: '',
    },
    showHeader: {
        type: Boolean,
        default: true,
    },
    /** Remove moldura/fundo quando o board já está dentro de outro painel */
    flush: {
        type: Boolean,
        default: false,
    },
    editable: {
        type: Boolean,
        default: false,
    },
    gameId: {
        type: Number,
        default: null,
    },
    availablePlayers: {
        type: Array,
        default: () => [],
    },
});

const colors = ['green', 'yellow', 'blue'];

const winners = computed(() => {
    const scores = colors
        .map((color) => props.teams?.[color]?.score)
        .filter((score) => score != null)
        .map(Number);

    if (scores.length < 2) return [];

    const best = Math.max(...scores);
    const leaders = colors.filter((color) => Number(props.teams?.[color]?.score) === best);

    // Empate geral não tem vencedor
    return leaders.length === scores.length ? [] : leaders;
});

const isWinner = (color) => winners.value.includes(color);
</script>

<template>
    <section class="teams-board" :class="{ 'teams-board--flush': flush }">
        <header v-if="showHeader" class="teams-board__header">
            <span class="teams-board__slash teams-board__slash--left" aria-hidden="true" />
            <div class="teams-board__heading">
                <h3 class="teams-board__title">{{ title }}</h3>
                <span v-if="statusLabel" class="teams-board__status">
                    <span class="teams-board__status-dot" aria-hidden="true" />
                    {{ statusLabel }}
                </span>
            </div>
            <span class="teams-board__slash teams-board__slash--right" aria-hidden="true" />
        </header>

        <div class="teams-board__grid">
            <TeamCard v-for="color in colors" :key="color" :color="color" :team="teams?.[color]"
                :winner="isWinner(color)" :editable="editable" :game-id="gameId"
                :available-players="availablePlayers" />
        </div>

        <div class="teams-board__footer" aria-hidden="true">
            <span class="teams-board__rule" />
            <span class="teams-board__emblem"><i class="fa-solid fa-futbol" /></span>
            <span class="teams-board__rule" />
        </div>
    </section>
</template>

<style scoped>
.teams-board {
    position: relative;
    padding: 12px 8px 10px;
    border: 1px solid rgba(100, 116, 139, 0.3);
    border-radius: 18px;
    background:
        radial-gradient(ellipse at top, rgba(56, 189, 248, 0.07), transparent 55%),
        linear-gradient(180deg, #0b1220 0%, #070b14 100%);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.04),
        0 12px 40px rgba(0, 0, 0, 0.35);
}

.teams-board--flush {
    padding: 0;
    border: 0;
    border-radius: 0;
    background: none;
    box-shadow: none;
}

.teams-board__header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 14px;
}

.teams-board__heading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}

.teams-board__title {
    margin: 0;
    color: #f8fafc;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.05rem;
    font-weight: 900;
    font-style: italic;
    letter-spacing: 0.1em;
    line-height: 1;
    text-transform: uppercase;
    text-shadow: 0 0 18px rgba(148, 163, 184, 0.35);
}

.teams-board__status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 12px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.8);
    color: #cbd5e1;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}

.teams-board__status-dot {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: #22c55e;
    box-shadow: 0 0 8px rgba(34, 197, 94, 0.9);
}

.teams-board__slash {
    flex: 0 0 auto;
    width: 26px;
    height: 12px;
    background:
        linear-gradient(115deg, transparent 0 40%, #22c55e 40% 58%, transparent 58%),
        linear-gradient(115deg, transparent 0 70%, #22c55e 70% 88%, transparent 88%);
    opacity: 0.85;
    filter: drop-shadow(0 0 6px rgba(34, 197, 94, 0.6));
}

.teams-board__slash--left {
    transform: scaleX(-1);
}

.teams-board__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 2px;
    align-items: start;
}

@media (max-width: 767px) {
    .teams-board__grid {
        margin: 0 -8px;
    }
}

.teams-board__footer {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
    padding: 0 6px;
}

.teams-board__rule {
    flex: 1 1 auto;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(148, 163, 184, 0.28), transparent);
}

.teams-board__emblem {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 30px;
    color: #94a3b8;
    font-size: 0.75rem;
    background: linear-gradient(180deg, rgba(148, 163, 184, 0.16), rgba(148, 163, 184, 0.04));
    box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.25);
    clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%);
}

@media (min-width: 1024px) {
    .teams-board {
        padding: 8px;
    }

    .teams-board__header {
        gap: 18px;
        margin-bottom: 20px;
    }

    .teams-board__title {
        font-size: 1.6rem;
    }

    .teams-board__status {
        font-size: 0.68rem;
    }

    .teams-board__slash {
        width: 40px;
        height: 16px;
    }

    .teams-board__grid {
        gap: 12px;
    }
}
</style>
