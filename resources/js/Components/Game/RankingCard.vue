<script setup>
import { computed } from 'vue';
import RankingPlayerCard from '@/Components/RankingPlayerCard.vue';
import { useClipboard } from '@/composables/useClipboard';

const props = defineProps({
    ranking: {
        type: Array,
        default: () => [],
    },
});

function assignMedals(players) {
    let medalRank = 0;
    let lastPoints = null;
    let lastGames = null;

    return players.map((player) => {
        if (player.total_points !== lastPoints || player.games_played !== lastGames) {
            medalRank++;
            lastPoints = player.total_points;
            lastGames = player.games_played;
        }

        if (player.total_points === 0) return { ...player, medal: null, zeroPoints: true };
        if (medalRank === 1) return { ...player, medal: 'gold' };
        if (medalRank === 2) return { ...player, medal: 'silver' };
        if (medalRank === 3) return { ...player, medal: 'bronze' };
        return { ...player, medal: null };
    });
}

function mapForm(lastResults = []) {
    return lastResults.map((result) => {
        if (Number(result) === 1) return 'win';
        if (Number(result) === 2) return 'draw';
        return 'loss';
    });
}

function toCardPlayer(player) {
    return {
        id: player.id,
        rank: player.rank,
        name: player.name,
        photo_front: player.photo_front,
        initial: player.initial,
        points: player.total_points,
        games: player.games_played,
        movement: player.rank_change,
        form: mapForm(player.last_results),
        theme: player.medal || 'default',
        win_streak: Number(player.win_streak) || 0,
        zeroPoints: player.zeroPoints,
        isGoalkeeper: player.position === 'goalkeeper' || player.is_goalkeeper === true,
        customizations: player.customizations ?? null,
    };
}

const linePlayers = computed(() =>
    assignMedals(props.ranking.filter((p) => p.position !== 'goalkeeper')).map(toCardPlayer),
);

const goalkeepers = computed(() =>
    props.ranking
        .filter((p) => p.position === 'goalkeeper')
        .map((player) => toCardPlayer({ ...player, medal: null, last_results: [] })),
);

const medalEmojis = { gold: '🥇', silver: '🥈', bronze: '🥉' };

const rankingMessage = computed(() => {
    const eligible = linePlayers.value.filter((p) => p.points >= 1);
    if (!eligible.length) return '';

    const lines = ['👑 REI DA QUADRA 2026', ''];

    for (const player of eligible) {
        const medal = player.theme && medalEmojis[player.theme] ? medalEmojis[player.theme] : '🔘';
        const stars = '⭐️'.repeat(player.points);
        lines.push(`${medal} ${player.name} (${player.games}p) ${stars}`);
    }

    return lines.join('\n');
});

const { label: copyRankingLabel, copy: copyRanking } = useClipboard();
const copyRankingMessage = () => copyRanking(rankingMessage.value);
</script>

<template>
    <div class="ranking-board py-2 lg:py-4 px-1 lg:px-4">
        <!-- <div class="ranking-board__header">
            <div>
                <h3 class="ranking-board__title">
                    <i class="fa-solid fa-shield-halved ranking-board__title-icon"></i>
                    RANKING
                </h3>
                <p class="ranking-board__subtitle">Classificação geral</p>
            </div>
            <div class="ranking-board__actions">
                <button v-if="rankingMessage" type="button" class="ranking-board__copy" @click="copyRankingMessage">
                    <i class="fa-brands fa-whatsapp"></i>
                    {{ copyRankingLabel }}
                </button>
            </div>
        </div> -->

        <div v-if="linePlayers.length" class="ranking-board__list">
            <div v-for="player in linePlayers" :key="player.id" class="ranking-board__item" :class="{
                'ranking-board__item--zero': player.zeroPoints,
                'ranking-board__item--podium': player.theme === 'gold' || player.theme === 'silver' || player.theme === 'bronze',
            }">
                <RankingPlayerCard :player="player" />
            </div>
        </div>
        <p v-else class="ranking-board__empty">Nenhum jogo finalizado ainda.</p>
    </div>

    <div class="ranking-board ranking-board--goalkeepers">
        <div class="ranking-board__header">
            <div>
                <h3 class="ranking-board__title">RANKING — GOLEIROS</h3>
                <p class="ranking-board__subtitle">Classificação dos goleiros</p>
            </div>
        </div>

        <div v-if="goalkeepers.length" class="ranking-board__list">
            <RankingPlayerCard v-for="player in goalkeepers" :key="player.id" :player="player" />
        </div>
        <p v-else class="ranking-board__empty">Nenhum goleiro com PJ ainda.</p>
    </div>
</template>

<style>
.ranking-board {
    position: relative;
    overflow: visible !important;
    border: 1px solid rgba(100, 116, 139, 0.35);
    border-radius: 18px;
    background:
        radial-gradient(ellipse at top left, rgba(56, 189, 248, 0.08), transparent 45%),
        radial-gradient(ellipse at bottom right, rgba(139, 92, 246, 0.08), transparent 40%),
        linear-gradient(180deg, #0b1220 0%, #070b14 100%);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.04),
        0 12px 40px rgba(0, 0, 0, 0.35);
}

.ranking-board--goalkeepers {
    margin-top: 1rem;
}

.ranking-board__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
    padding: 4px 6px 10px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.15);
}

.ranking-board__title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: #f8fafc;
    font-size: 1.15rem;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.ranking-board__title-icon {
    color: #fbbf24;
    filter: drop-shadow(0 0 8px rgba(251, 191, 36, 0.55));
}

.ranking-board__subtitle {
    margin: 4px 0 0;
    color: #a78bfa;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}

.ranking-board__actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ranking-board__copy {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid rgba(34, 211, 238, 0.35);
    border-radius: 8px;
    background: rgba(8, 145, 178, 0.2);
    padding: 6px 12px;
    color: #67e8f9;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    transition: background 150ms ease, border-color 150ms ease;
}

.ranking-board__copy:hover {
    background: rgba(8, 145, 178, 0.35);
    border-color: rgba(34, 211, 238, 0.65);
}

.ranking-board__list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding-top: 10px;
    overflow: visible;
}

.ranking-board__item {
    position: relative;
    overflow: visible;
}

.ranking-board__item--podium,
.ranking-board__item:has(.ranking-card--podium) {
    z-index: 4;
    overflow: visible;
}

/* Espaço maior só entre os do pódio; do 3º para baixo usa o gap normal */
.ranking-board__item--podium+.ranking-board__item--podium {
    margin-top: 14px;
}

.ranking-board__item--zero {
    opacity: 0.72;
    filter: saturate(0.7);
}

.ranking-board__empty {
    margin: 8px 0 0;
    padding: 24px 12px;
    color: #64748b;
    font-size: 0.9rem;
    font-weight: 600;
    text-align: center;
}
</style>
