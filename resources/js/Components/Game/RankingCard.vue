<script setup>
import { computed, ref, onMounted, nextTick, watch } from 'vue';
import DataTable from '@/Components/DataTable.vue';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';
import StreakPlayerName from '@/Components/Game/StreakPlayerName.vue';
import { useClipboard } from '@/composables/useClipboard';
import { useFireParticles } from '@/composables/useFireParticles';
import { streakParticleTargets, streakRowClass } from '@/composables/streakTiers';

const showPhotos = ref(true);
const rankingWrapper = ref(null);
const { init: initFire } = useFireParticles();

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
    const streakClass = streakRowClass(row.win_streak);
    if (streakClass) classes.push(streakClass);
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
    nextTick(() => setTimeout(() => {
        initFire(rankingWrapper.value, streakParticleTargets());
    }, 200));
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
                <StreakPlayerName :name="row.name" :streak="row.win_streak">
                    <PositionBadge
                        v-if="!showPhotos"
                        :position="row.position"
                        :label="positionLabels[row.position] || row.position"
                    />
                </StreakPlayerName>
            </template>
            <template #cell-last_results="{ row }">
                <div class="flex items-center justify-center gap-0.25">
                    <span
                        v-for="(result, i) in row.last_results"
                        :key="i"
                        class="inline-flex items-center justify-center rounded-full"
                        :class="i === row.last_results.length - 1
                            ? (result === 1
                                ? 'text-lg underline underline-offset-2 decoration-green-600'
                                : 'text-lg underline underline-offset-2 decoration-red-500')
                            : ''"
                    >
                        <i v-if="result === 1" class="fa-solid fa-circle-check text-green-600"></i>
                        <i v-else class="fa-solid fa-circle-xmark text-red-500"></i>
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
/* Soft cell tint — glow lives on .qnf-streak-aura overlays (div, iOS-safe). */
.qnf-streak--hot > td {
    background-color: rgba(255, 120, 0, 0.10);
}

.qnf-streak--legendary > td {
    background-color: rgba(124, 58, 237, 0.12);
}

.qnf-streak-aura {
    -webkit-transform: translateZ(0);
    transform: translateZ(0);
    -webkit-animation: qnfStreakPulseHot 2.2s ease-in-out infinite;
    animation: qnfStreakPulseHot 2.2s ease-in-out infinite;
}

.qnf-streak-aura--legendary {
    -webkit-animation-name: qnfStreakPulseLegendary;
    animation-name: qnfStreakPulseLegendary;
}

/* Tighter vertical outer glow + negative spread = cleaner edge when two streaks stack. */
@keyframes qnfStreakPulseHot {
    0%, 100% {
        box-shadow:
            inset 0 0 22px rgba(255, 140, 0, 0.42),
            inset 0 0 50px rgba(255, 180, 0, 0.18),
            0 0 0 2px rgba(255, 120, 0, 0.7),
            -10px 0 16px -6px rgba(255, 100, 0, 0.4),
            10px 0 16px -6px rgba(255, 180, 0, 0.35),
            0 -4px 10px -6px rgba(255, 120, 0, 0.35),
            0 4px 10px -6px rgba(255, 120, 0, 0.35);
    }
    50% {
        box-shadow:
            inset 0 0 30px rgba(255, 140, 0, 0.52),
            inset 0 0 70px rgba(255, 180, 0, 0.22),
            0 0 0 2px rgba(255, 140, 0, 0.85),
            -12px 0 20px -5px rgba(255, 120, 0, 0.5),
            12px 0 20px -5px rgba(255, 200, 0, 0.42),
            0 -5px 12px -5px rgba(255, 140, 0, 0.45),
            0 5px 12px -5px rgba(255, 140, 0, 0.45);
    }
}

@-webkit-keyframes qnfStreakPulseHot {
    0%, 100% {
        box-shadow:
            inset 0 0 22px rgba(255, 140, 0, 0.42),
            inset 0 0 50px rgba(255, 180, 0, 0.18),
            0 0 0 2px rgba(255, 120, 0, 0.7),
            -10px 0 16px -6px rgba(255, 100, 0, 0.4),
            10px 0 16px -6px rgba(255, 180, 0, 0.35),
            0 -4px 10px -6px rgba(255, 120, 0, 0.35),
            0 4px 10px -6px rgba(255, 120, 0, 0.35);
    }
    50% {
        box-shadow:
            inset 0 0 30px rgba(255, 140, 0, 0.52),
            inset 0 0 70px rgba(255, 180, 0, 0.22),
            0 0 0 2px rgba(255, 140, 0, 0.85),
            -12px 0 20px -5px rgba(255, 120, 0, 0.5),
            12px 0 20px -5px rgba(255, 200, 0, 0.42),
            0 -5px 12px -5px rgba(255, 140, 0, 0.45),
            0 5px 12px -5px rgba(255, 140, 0, 0.45);
    }
}

@keyframes qnfStreakPulseLegendary {
    0%, 100% {
        box-shadow:
            inset 0 0 22px rgba(124, 58, 237, 0.42),
            inset 0 0 50px rgba(168, 85, 247, 0.2),
            0 0 0 2px rgba(147, 51, 234, 0.75),
            -10px 0 16px -6px rgba(109, 40, 217, 0.45),
            10px 0 16px -6px rgba(192, 132, 252, 0.38),
            0 -4px 10px -6px rgba(147, 51, 234, 0.4),
            0 4px 10px -6px rgba(147, 51, 234, 0.4);
    }
    50% {
        box-shadow:
            inset 0 0 30px rgba(139, 92, 246, 0.52),
            inset 0 0 70px rgba(216, 70, 239, 0.26),
            0 0 0 2px rgba(168, 85, 247, 0.9),
            -12px 0 20px -5px rgba(124, 58, 237, 0.55),
            12px 0 20px -5px rgba(232, 121, 249, 0.45),
            0 -5px 12px -5px rgba(168, 85, 247, 0.5),
            0 5px 12px -5px rgba(168, 85, 247, 0.5);
    }
}

@-webkit-keyframes qnfStreakPulseLegendary {
    0%, 100% {
        box-shadow:
            inset 0 0 22px rgba(124, 58, 237, 0.42),
            inset 0 0 50px rgba(168, 85, 247, 0.2),
            0 0 0 2px rgba(147, 51, 234, 0.75),
            -10px 0 16px -6px rgba(109, 40, 217, 0.45),
            10px 0 16px -6px rgba(192, 132, 252, 0.38),
            0 -4px 10px -6px rgba(147, 51, 234, 0.4),
            0 4px 10px -6px rgba(147, 51, 234, 0.4);
    }
    50% {
        box-shadow:
            inset 0 0 30px rgba(139, 92, 246, 0.52),
            inset 0 0 70px rgba(216, 70, 239, 0.26),
            0 0 0 2px rgba(168, 85, 247, 0.9),
            -12px 0 20px -5px rgba(124, 58, 237, 0.55),
            12px 0 20px -5px rgba(232, 121, 249, 0.45),
            0 -5px 12px -5px rgba(168, 85, 247, 0.5),
            0 5px 12px -5px rgba(168, 85, 247, 0.5);
    }
}

@media (prefers-reduced-motion: reduce) {
    .qnf-streak-aura {
        -webkit-animation: none !important;
        animation: none !important;
    }

    .qnf-streak-aura--hot {
        box-shadow:
            inset 0 0 22px rgba(255, 140, 0, 0.42),
            0 0 0 2px rgba(255, 120, 0, 0.7),
            -8px 0 12px -6px rgba(255, 100, 0, 0.35),
            8px 0 12px -6px rgba(255, 180, 0, 0.3);
    }

    .qnf-streak-aura--legendary {
        box-shadow:
            inset 0 0 22px rgba(124, 58, 237, 0.42),
            0 0 0 2px rgba(147, 51, 234, 0.75),
            -8px 0 12px -6px rgba(109, 40, 217, 0.4),
            8px 0 12px -6px rgba(192, 132, 252, 0.35);
    }
}
</style>
