<script setup>
import { computed } from 'vue';
import DataTable from '@/Components/DataTable.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';
import { useClipboard } from '@/composables/useClipboard';

const props = defineProps({
    ranking: {
        type: Array,
        default: () => [],
    },
});

const positionLabels = {
    goalkeeper: 'Goleiro',
    fixed: 'Fixo',
    winger: 'Ala',
    pivot: 'Pivô',
};

const medalColors = {
    gold: 'text-[#B8860B]',
    silver: 'text-[#6B7280]',
    bronze: 'text-[#8C4A2F]',
};

const rowBgColors = {
    gold: 'bg-[#FFF4CC]',
    silver: 'bg-[#F1F5F9]',
    bronze: 'bg-[#FCE7DF]',
    zero: 'bg-red-300',
};

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

const linePlayers = computed(() =>
    assignMedals(props.ranking.filter((p) => p.position !== 'goalkeeper')),
);

const goalkeepers = computed(() =>
    props.ranking.filter((p) => p.position === 'goalkeeper'),
);

const lineRowClass = (row) => {
    if (row.zeroPoints) return rowBgColors.zero;
    if (row.medal) return rowBgColors[row.medal];
    return '';
};

const lineColumns = [
    { key: 'rank', label: '#' },
    { key: 'name', label: 'Jogador', class: 'font-bold text-lg text-gray-900' },
    { key: 'total_points', label: 'Pontos', align: 'center', class: 'font-bold text-lg text-gray-900' },
    { key: 'games_played', label: 'Jogos', align: 'center', class: 'font-bold text-lg text-gray-900' },
    { key: 'rank_change', label: '', align: 'center' },
    // { key: 'win_streak', label: 'Sequência', align: 'center', class: 'font-bold text-lg text-gray-900' },
];

const goalkeeperColumns = [
    { key: 'rank', label: '#' },
    { key: 'name', label: 'Jogador', class: 'font-bold text-lg text-gray-900' },
    { key: 'total_points', label: 'Pontos', align: 'center', class: 'font-bold text-lg text-gray-900' },
    { key: 'games_played', label: 'Jogos', align: 'center', class: 'font-bold text-lg text-gray-900' },
    { key: 'rank_change', label: '', align: 'center' },
];

// --- Ranking WhatsApp message ---

const medalEmojis = { gold: '🥇', silver: '🥈', bronze: '🥉' };

const rankingMessage = computed(() => {
    const eligible = linePlayers.value.filter((p) => p.total_points >= 1);
    if (!eligible.length) return '';

    const lines = ['👑 REI DA QUADRA 2026', ''];

    for (const player of eligible) {
        const medal = player.medal ? medalEmojis[player.medal] : '🔘';
        const stars = '⭐️'.repeat(player.total_points);
        lines.push(`${medal} ${player.name} (${player.games_played}p) ${stars}`);
    }

    return lines.join('\n');
});

const { label: copyRankingLabel, copy: copyRanking } = useClipboard();
const copyRankingMessage = () => copyRanking(rankingMessage.value);
</script>

<template>
    <div class="rounded-xl bg-white p-2 lg:p-4 shadow">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900">Ranking - Linha</h3>
            <button v-if="rankingMessage" @click="copyRankingMessage"
                class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 transition">
                <i class="fa-brands fa-whatsapp mr-1"></i>
                {{ copyRankingLabel }}
            </button>
        </div>
        <DataTable :columns="lineColumns" :rows="linePlayers" :row-class="lineRowClass"
            empty-message="Nenhum jogo finalizado ainda.">
            <template #cell-rank="{ row }">
                <span v-if="row.medal" class="text-lg">
                    <i class="!text-2xl fa-solid fa-medal drop-shadow-[0_1px_1px_rgba(0,0,0,0.25)]"
                        :class="medalColors[row.medal]"></i>
                </span>
                <span v-else class="font-bold text-lg text-gray-900">{{ row.rank }}º</span>
            </template>
            <template #cell-name="{ row }">
                <div class="flex items-center gap-2">
                    <i v-if="row.win_streak >= 3" class="fa-solid fa-fire qnf-fire"></i>
                    <span class="font-medium text-gray-900">{{ row.name }}</span>
                    <PositionBadge :position="row.position" :label="positionLabels[row.position] || row.position" />
                </div>
            </template>
            <template #cell-rank_change="{ row }">
                <span v-if="row.rank_change === null" class="text-xs text-blue-500 font-semibold">NOVO</span>
                <span v-else-if="row.rank_change > 0" class="text-green-600 flex items-center justify-center gap-0.5">
                    <i class="fa-solid fa-circle-up"></i>
                    <span class="text-xs font-bold">{{ row.rank_change }}</span>
                </span>
                <span v-else-if="row.rank_change < 0" class="text-red-500 flex items-center justify-center gap-0.5">
                    <i class="fa-solid fa-circle-down"></i>
                    <span class="text-xs font-bold">{{ Math.abs(row.rank_change) }}</span>
                </span>
                <span v-else class="text-gray-400">
                    <i class="fa-solid fa-circle-minus"></i>
                </span>
            </template>
        </DataTable>
    </div>

    <div class="rounded-xl bg-white p-2 lg:p-4 shadow">
        <h3 class="mb-3 text-base font-semibold text-gray-900">Ranking - Goleiros</h3>

        <DataTable :columns="goalkeeperColumns" :rows="goalkeepers" empty-message="Nenhum goleiro com jogos ainda.">
            <template #cell-rank="{ row }">
                <span class="font-bold text-lg text-gray-900">{{ row.rank }}º</span>
            </template>
            <template #cell-name="{ row }">
                <div class="flex items-center gap-2">
                    <span class="font-medium text-gray-900">{{ row.name }}</span>
                    <PositionBadge :position="row.position" :label="positionLabels[row.position] || row.position" />
                </div>
            </template>
            <template #cell-rank_change="{ row }">
                <span v-if="row.rank_change === null" class="text-xs text-blue-500 font-semibold">NOVO</span>
                <span v-else-if="row.rank_change > 0" class="text-green-600 flex items-center justify-center gap-0.5">
                    <i class="fa-solid fa-circle-up"></i>
                    <span class="text-xs font-bold">{{ row.rank_change }}</span>
                </span>
                <span v-else-if="row.rank_change < 0" class="text-red-500 flex items-center justify-center gap-0.5">
                    <i class="fa-solid fa-circle-down"></i>
                    <span class="text-xs font-bold">{{ Math.abs(row.rank_change) }}</span>
                </span>
                <span v-else class="text-gray-400">
                    <i class="fa-solid fa-circle-minus"></i>
                </span>
            </template>
        </DataTable>
    </div>
</template>

<style scoped>
.qnf-fire {
    color: #ff3b30;
    filter: drop-shadow(0 0 10px rgba(255, 90, 0, .75));
    animation: qnfFlame .55s ease-in-out infinite alternate;
}

@keyframes qnfFlame {
    0% {
        transform: translateY(1px) scale(.98) rotate(-6deg);
        filter: drop-shadow(0 0 8px rgba(255, 110, 0, .65));
    }
    100% {
        transform: translateY(-2px) scale(1.08) rotate(6deg);
        filter: drop-shadow(0 0 14px rgba(255, 180, 0, .85));
    }
}

@media (prefers-reduced-motion: reduce) {
    .qnf-fire {
        animation: none !important;
    }
}
</style>
