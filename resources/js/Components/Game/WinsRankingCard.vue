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
    let lastScore = null;
    let lastAvg = null;

    return players.map((player) => {
        if (player.total_score !== lastScore || player.avg_score !== lastAvg) {
            medalRank++;
            lastScore = player.total_score;
            lastAvg = player.avg_score;
        }

        if (player.total_score === 0) return { ...player, medal: null, zeroPoints: true };
        if (medalRank === 1) return { ...player, medal: 'gold' };
        if (medalRank === 2) return { ...player, medal: 'silver' };
        if (medalRank === 3) return { ...player, medal: 'bronze' };
        return { ...player, medal: null };
    });
}

function toCardPlayer(player) {
    return {
        id: player.id,
        rank: player.rank,
        name: player.name,
        photo_front: player.photo_front,
        initial: player.initial,
        points: player.total_score,
        games: player.games_played,
        movement: null,
        form: [],
        theme: player.medal || 'default',
        win_streak: 0,
        pointsLabel: 'VIT',
        avg: player.avg_score ?? null,
        zeroPoints: player.zeroPoints,
        customizations: player.customizations ?? null,
    };
}

const players = computed(() =>
    assignMedals(props.ranking).map(toCardPlayer),
);

const medalEmojis = { gold: '🥇', silver: '🥈', bronze: '🥉' };

const rankingMessage = computed(() => {
    const eligible = players.value.filter((p) => p.points >= 1);
    if (!eligible.length) return '';

    const lines = ['🏆 RANKING DE VITÓRIAS', ''];

    for (const player of eligible) {
        const medal = player.theme && medalEmojis[player.theme] ? medalEmojis[player.theme] : '🔘';
        const avg = player.avg != null ? ` · média ${player.avg}` : '';
        lines.push(`${medal} ${player.name} — ${player.points} vit (${player.games}p)${avg}`);
    }

    return lines.join('\n');
});

const { label: copyRankingLabel, copy: copyRanking } = useClipboard();
const copyRankingMessage = () => copyRanking(rankingMessage.value);
</script>

<template>
    <div class="ranking-board py-2 lg:py-4 px-1 lg:px-4">
        <div v-if="players.length" class="ranking-board__list">
            <div
                v-for="player in players"
                :key="player.id"
                class="ranking-board__item"
                :class="{
                    'ranking-board__item--zero': player.zeroPoints,
                    'ranking-board__item--podium': player.theme === 'gold' || player.theme === 'silver' || player.theme === 'bronze',
                }"
            >
                <RankingPlayerCard :player="player" />
            </div>
        </div>
        <p v-else class="ranking-board__empty">Nenhum jogo finalizado ainda.</p>
    </div>
</template>
