<script setup>
import { computed, ref, onMounted, onUnmounted, nextTick, watch } from 'vue';
import DataTable from '@/Components/DataTable.vue';
import FireIcon from '@/Components/Game/FireIcon.vue';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';
import { useClipboard } from '@/composables/useClipboard';

const showPhotos = ref(true);
const rankingWrapper = ref(null);
let canvasInstances = [];
let animFrameId = null;

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

// --- Native Canvas Fire Particles ---

const COLORS = ['#ff3b00', '#ff6a00', '#ff9500', '#ffcc00', '#ffee00', '#fff2a0'];

function rand(min, max) {
    return Math.random() * (max - min) + min;
}

function createParticle(w, h) {
    return {
        x: rand(0, w),
        y: h + rand(0, 10),
        vx: rand(-0.3, 0.3),
        vy: rand(-0.2, -0.8),
        r: rand(1.5, 4),
        opacity: rand(0.5, 1),
        color: COLORS[Math.floor(rand(0, COLORS.length))],
        life: 0,
        maxLife: rand(100, 280),
        drift: rand(-0.015, 0.015),
    };
}

function initFireCanvas() {
    destroyFireCanvas();
    if (!rankingWrapper.value) return;

    const wrapper = rankingWrapper.value;
    const rows = wrapper.querySelectorAll('.qnf-streak-row');
    if (!rows.length) return;

    const wrapperRect = wrapper.getBoundingClientRect();

    rows.forEach((tr, i) => {
        const rect = tr.getBoundingClientRect();
        const canvas = document.createElement('canvas');
        const dpr = window.devicePixelRatio || 1;
        const w = rect.width;
        const h = rect.height;

        canvas.width = w * dpr;
        canvas.height = h * dpr;
        canvas.style.cssText = `
            position: absolute;
            top: ${rect.top - wrapperRect.top}px;
            left: ${rect.left - wrapperRect.left}px;
            width: ${w}px;
            height: ${h}px;
            pointer-events: none;
            z-index: 4;
        `;

        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        wrapper.appendChild(canvas);

        const particles = [];
        for (let j = 0; j < 30; j++) {
            const p = createParticle(w, h);
            p.life = rand(0, p.maxLife);
            particles.push(p);
        }

        canvasInstances.push({ canvas, ctx, particles, w, h });
    });

    animate();
}

function animate() {
    for (const inst of canvasInstances) {
        const { ctx, particles, w, h } = inst;
        ctx.clearRect(0, 0, w, h);

        for (let i = particles.length - 1; i >= 0; i--) {
            const p = particles[i];
            p.life++;
            p.x += p.vx + Math.sin(p.life * 0.05) * p.drift * 10;
            p.y += p.vy;
            p.vx += p.drift;

            const lifeRatio = p.life / p.maxLife;
            const alpha = lifeRatio < 0.1
                ? p.opacity * (lifeRatio / 0.1)
                : p.opacity * (1 - lifeRatio);

            if (p.life >= p.maxLife || alpha <= 0) {
                particles[i] = createParticle(w, h);
                continue;
            }

            const shrink = 1 - lifeRatio * 0.5;

            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r * shrink, 0, Math.PI * 2);
            ctx.fillStyle = p.color;
            ctx.globalAlpha = Math.max(0, alpha);
            ctx.fill();

            // Glow
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r * shrink * 2.5, 0, Math.PI * 2);
            ctx.fillStyle = p.color;
            ctx.globalAlpha = Math.max(0, alpha * 0.15);
            ctx.fill();
        }

        ctx.globalAlpha = 1;
    }

    animFrameId = requestAnimationFrame(animate);
}

function destroyFireCanvas() {
    if (animFrameId) {
        cancelAnimationFrame(animFrameId);
        animFrameId = null;
    }
    for (const { canvas } of canvasInstances) {
        canvas.remove();
    }
    canvasInstances = [];
}

onMounted(() => {
    nextTick(() => setTimeout(initFireCanvas, 200));
});

onUnmounted(destroyFireCanvas);

watch([linePlayers, showPhotos], () => {
    nextTick(() => setTimeout(initFireCanvas, 200));
});
</script>

<template>
    <div ref="rankingWrapper" class="rounded-xl bg-white sm:px-2 lg:p-4 shadow" style="position: relative;">
        <div class="flex items-center justify-between p-2">
            <h3 class="text-base font-semibold text-gray-900">Ranking - Linha</h3>
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
                    <div :class="row.win_streak ? 'flex items-center lg:gap-1' : ''">
                        <FireIcon :streak="row.win_streak" />
                        <span class="text-sm md:text-base lg:text-lg font-medium text-gray-900">
                            {{ row.name }}
                        </span>
                        <FireIcon :streak="row.win_streak" />
                    </div>
                    <PositionBadge v-if="!showPhotos" :position="row.position"
                        :label="positionLabels[row.position] || row.position" />
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

    <div class="rounded-xl bg-white p-2 lg:p-4 shadow">
        <h3 class="mb-3 text-base font-semibold text-gray-900">Ranking - Goleiros</h3>

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
