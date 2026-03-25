<script setup>
import { computed, ref, onMounted, nextTick, watch } from 'vue';
import DataTable from '@/Components/DataTable.vue';
import FireIcon from '@/Components/Game/FireIcon.vue';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';
import { useClipboard } from '@/composables/useClipboard';
import { useFireParticles } from '@/composables/useFireParticles';

const showPhotos = ref(true);
const rankingWrapper = ref(null);
const { init: initFire, destroy: destroyFire } = useFireParticles();

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
    const classes = [];
    if (row.win_streak >= 3) classes.push('qnf-streak-row');
    if (row.zeroPoints) classes.push(rowBgColors.zero);
    else if (row.medal) classes.push(rowBgColors[row.medal]);
    return classes.join(' ');
};

const baseLineColumns = [
    { key: 'rank', label: 'Rank', align: 'center' },
    { key: 'photo', label: 'Foto', align: 'center' },
    { key: 'name', label: 'Jogador', align: 'center', class: 'font-bold text-sm sm:text-base lg:text-lg text-gray-900' },
    { key: 'total_points', label: 'PTS', align: 'center', class: 'font-bold text-sm sm:text-base lg:text-lg text-gray-900' },
    { key: 'games_played', label: 'PJ', align: 'center', class: 'font-bold text-sm sm:text-base lg:text-lg text-gray-900' },
    { key: 'last_results', label: 'Últimas 5', align: 'center' },
];

const baseGoalkeeperColumns = [
    { key: 'rank', label: 'Rank', align: 'center' },
    { key: 'photo', label: '' },
    { key: 'name', label: 'Jogador', class: 'font-bold text-sm sm:text-base lg:text-lg text-gray-900' },
    { key: 'total_points', label: 'PTS', align: 'center', class: 'font-bold text-sm sm:text-base lg:text-lg text-gray-900' },
    { key: 'games_played', label: 'PJ', align: 'center', class: 'font-bold text-sm sm:text-base lg:text-lg text-gray-900' },
];

const lineColumns = computed(() =>
    showPhotos.value ? baseLineColumns : baseLineColumns.filter((c) => c.key !== 'photo'),
);

const goalkeeperColumns = computed(() =>
    showPhotos.value ? baseGoalkeeperColumns : baseGoalkeeperColumns.filter((c) => c.key !== 'photo'),
);

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

function refreshFire() {
    nextTick(() => setTimeout(() => initFire(rankingWrapper.value, '.qnf-streak-row'), 200));
}

onMounted(refreshFire);

watch([linePlayers, showPhotos], refreshFire);
</script>

<template>
    <div ref="rankingWrapper" class="rounded-xl bg-white sm:px-2 lg:p-4 shadow" style="position: relative;">
        <div class="flex items-center justify-between p-2">
            <h3 class="text-base font-semibold text-gray-900">RANKING</h3>
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-1.5 text-sm text-gray-600 cursor-pointer select-none">
                    <input type="checkbox" v-model="showPhotos"
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer" />
                    Fotos
                </label>
                <button v-if="rankingMessage" @click="copyRankingMessage"
                    class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 transition">
                    <i class="fa-brands fa-whatsapp mr-1"></i>
                    {{ copyRankingLabel }}
                </button>
            </div>
        </div>
        <DataTable :columns="lineColumns" :rows="linePlayers" :row-class="lineRowClass"
            empty-message="Nenhum jogo finalizado ainda.">
            <template #cell-rank="{ row }">
                <div class="flex flex-col items-center">
                    <span v-if="row.medal" class="text-lg">
                        <i class="!text-2xl fa-solid fa-medal drop-shadow-[0_1px_1px_rgba(0,0,0,0.25)]"
                            :class="medalColors[row.medal]"></i>
                    </span>
                    <span v-else class="font-bold text-sm sm:text-base lg:text-lg text-gray-900">{{ row.rank }}º</span>
                    <span v-if="row.rank_change === null" class="text-xs text-blue-500 font-semibold">NOVO</span>
                    <span v-else-if="row.rank_change > 0" class="text-green-600 flex items-center gap-0.5">
                        <i class="fa-solid fa-circle-up text-xs"></i>
                        <span class="text-xs font-bold">{{ row.rank_change }}</span>
                    </span>
                    <span v-else-if="row.rank_change < 0" class="text-red-500 flex items-center gap-0.5">
                        <i class="fa-solid fa-circle-down text-xs"></i>
                        <span class="text-xs font-bold">{{ Math.abs(row.rank_change) }}</span>
                    </span>
                    <span v-else class="text-gray-400">
                        <i class="fa-solid fa-circle-minus text-xs"></i>
                    </span>
                </div>
            </template>
            <template #cell-photo="{ row }">
                <PlayerPhoto :src="row.photo_front" :initial="row.initial" :alt="row.name" />
            </template>
            <template #cell-name="{ row }">
                <div>
                    <div :class="row.win_streak >= 3 ? 'flex items-center justify-center lg:gap-1' : ''">
                        <FireIcon :streak="row.win_streak" />
                        <span class="text-sm md:text-base lg:text-lg font-medium text-gray-900">
                            {{ row.name }}
                        </span>
                        <FireIcon :streak="row.win_streak" />

                        <PositionBadge v-if="!showPhotos" :position="row.position"
                            :label="positionLabels[row.position] || row.position" />
                    </div>
                </div>
            </template>
            <template #cell-last_results="{ row }">
                <div class="flex items-center justify-center">
                    <span v-for="(result, i) in row.last_results" :key="i">
                        <i v-if="result === 1" class="fa-regular fa-circle-check text-green-600 text-xs"></i>
                        <i v-else class="fa-regular fa-circle-xmark text-red-500 text-xs"></i>
                    </span>
                </div>
            </template>
        </DataTable>
    </div>

    <div class="rounded-xl bg-white sm:px-2 lg:p-4 shadow">
        <h3 class="mb-3 text-base font-semibold text-gray-900 p-2">Ranking - Goleiros</h3>

        <DataTable :columns="goalkeeperColumns" :rows="goalkeepers" empty-message="Nenhum goleiro com PJ ainda.">
            <template #cell-rank="{ row }">
                <div class="flex flex-col items-center">
                    <span class="font-bold text-sm sm:text-base lg:text-lg text-gray-900">{{ row.rank }}º</span>
                    <span v-if="row.rank_change === null" class="text-xs text-blue-500 font-semibold">NOVO</span>
                    <span v-else-if="row.rank_change > 0" class="text-green-600 flex items-center gap-0.5">
                        <i class="fa-solid fa-circle-up text-xs"></i>
                        <span class="text-xs font-bold">{{ row.rank_change }}</span>
                    </span>
                    <span v-else-if="row.rank_change < 0" class="text-red-500 flex items-center gap-0.5">
                        <i class="fa-solid fa-circle-down text-xs"></i>
                        <span class="text-xs font-bold">{{ Math.abs(row.rank_change) }}</span>
                    </span>
                    <span v-else class="text-gray-400">
                        <i class="fa-solid fa-circle-minus text-xs"></i>
                    </span>
                </div>
            </template>
            <template #cell-photo="{ row }">
                <PlayerPhoto :src="row.photo_front" :initial="row.initial" :alt="row.name" />
            </template>
            <template #cell-name="{ row }">
                <span class="font-medium text-gray-900">{{ row.name }}</span>
            </template>
        </DataTable>
    </div>
</template>

<style>
/* Pulsing glow around streak rows */
.qnf-streak-row {
    animation: qnfOuterGlow 2s ease-in-out infinite;
}

@keyframes qnfOuterGlow {
    0% {
        box-shadow:
            0 0 12px 3px rgba(255, 59, 0, 0.5),
            0 0 30px 8px rgba(255, 90, 0, 0.2),
            inset 0 0 40px 10px rgba(255, 80, 0, 0.25),
            inset 0 0 80px 20px rgba(255, 120, 0, 0.1);
    }

    33% {
        box-shadow:
            0 0 18px 5px rgba(255, 140, 0, 0.6),
            0 0 40px 10px rgba(255, 120, 0, 0.25),
            inset 0 0 50px 15px rgba(255, 120, 0, 0.3),
            inset 0 0 100px 25px rgba(255, 160, 0, 0.12);
    }

    66% {
        box-shadow:
            0 0 14px 4px rgba(255, 200, 0, 0.5),
            0 0 35px 8px rgba(255, 160, 0, 0.2),
            inset 0 0 45px 12px rgba(255, 100, 0, 0.28),
            inset 0 0 90px 22px rgba(255, 140, 0, 0.1);
    }

    100% {
        box-shadow:
            0 0 12px 3px rgba(255, 59, 0, 0.5),
            0 0 30px 8px rgba(255, 90, 0, 0.2),
            inset 0 0 40px 10px rgba(255, 80, 0, 0.25),
            inset 0 0 80px 20px rgba(255, 120, 0, 0.1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .qnf-streak-row {
        animation: none !important;
        box-shadow: 0 0 12px 3px rgba(255, 100, 0, 0.4);
    }
}
</style>
