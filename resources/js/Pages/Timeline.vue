<script setup>
import { computed, ref, watch, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';

const props = defineProps({
    snapshots: {
        type: Array,
        default: () => [],
    },
});

const ROW_HEIGHT = 64;
const ROUND_INTERVAL_MS = 1200;

const currentIndex = ref(0);
const isPlaying = ref(false);
let playTimer = null;

const currentSnapshot = computed(() => props.snapshots[currentIndex.value] ?? null);

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

const displayPlayers = computed(() =>
    assignMedals(currentSnapshot.value?.ranking ?? []),
);

const listHeight = computed(() => ROW_HEIGHT * Math.max(displayPlayers.value.length, 1));

const formattedDate = computed(() => {
    const date = currentSnapshot.value?.date;
    if (!date) return '';

    const parsed = new Date(date);
    if (Number.isNaN(parsed.getTime())) return '';

    return parsed.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
});

function rowClass(player) {
    if (player.zeroPoints) return rowBgColors.zero;
    if (player.medal) return rowBgColors[player.medal];
    return 'bg-white';
}

function stopPlayback() {
    isPlaying.value = false;
    if (playTimer) {
        clearInterval(playTimer);
        playTimer = null;
    }
}

function togglePlay() {
    if (!props.snapshots.length) return;

    if (isPlaying.value) {
        stopPlayback();
        return;
    }

    if (currentIndex.value >= props.snapshots.length - 1) {
        currentIndex.value = 0;
    }

    isPlaying.value = true;
    playTimer = setInterval(() => {
        if (currentIndex.value < props.snapshots.length - 1) {
            currentIndex.value++;
        } else {
            stopPlayback();
        }
    }, ROUND_INTERVAL_MS);
}

function resetTimeline() {
    stopPlayback();
    currentIndex.value = 0;
}

watch(currentIndex, () => {
    if (isPlaying.value && currentIndex.value >= props.snapshots.length - 1) {
        stopPlayback();
    }
});

onUnmounted(stopPlayback);
</script>

<template>
    <AppLayout title="Timeline">
        <template #header>
            <TitleCard />
        </template>

        <div class="p-1 lg:p-4">
            <div class="mx-auto max-w-2xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Ranking na linha do tempo</h3>
                            <p v-if="currentSnapshot" class="mt-1 text-sm text-gray-600">
                                Rodada {{ currentSnapshot.round }}
                                <span v-if="formattedDate">· {{ formattedDate }}</span>
                            </p>
                            <p v-else class="mt-1 text-sm text-gray-500">Nenhuma rodada finalizada ainda.</p>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="resetTimeline"
                                :disabled="!snapshots.length"
                                class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                title="Reiniciar"
                            >
                                <i class="fa-solid fa-backward-step"></i>
                            </button>
                            <button
                                type="button"
                                @click="togglePlay"
                                :disabled="!snapshots.length"
                                class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <i :class="isPlaying ? 'fa-solid fa-pause' : 'fa-solid fa-play'"></i>
                                {{ isPlaying ? 'Pausar' : 'Play' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="snapshots.length > 1" class="mb-4">
                        <input
                            v-model.number="currentIndex"
                            type="range"
                            min="0"
                            :max="snapshots.length - 1"
                            step="1"
                            class="w-full accent-indigo-600"
                            @mousedown="stopPlayback"
                            @touchstart="stopPlayback"
                        />
                        <div class="mt-1 flex justify-between text-xs text-gray-500">
                            <span>Rodada {{ snapshots[0]?.round }}</span>
                            <span>Rodada {{ snapshots[snapshots.length - 1]?.round }}</span>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="w-full table-fixed">
                            <thead>
                                <tr class="border-b bg-gray-50 text-xs font-semibold uppercase text-gray-600">
                                    <th class="w-14 px-2 py-2 text-center">Rank</th>
                                    <th class="w-16 px-2 py-2 text-center">Foto</th>
                                    <th class="px-2 py-2 text-left">Jogador</th>
                                    <th class="w-20 px-2 py-2 text-center">PTS/PJ</th>
                                </tr>
                            </thead>
                        </table>

                        <div
                            v-if="displayPlayers.length"
                            class="relative w-full"
                            :style="{ height: `${listHeight}px` }"
                        >
                            <div
                                v-for="(player, rowIndex) in displayPlayers"
                                :key="player.id"
                                class="timeline-row absolute inset-x-0 flex w-full items-center border-b border-gray-100 transition-transform duration-700 ease-in-out"
                                :class="rowClass(player)"
                                :style="{
                                    height: `${ROW_HEIGHT}px`,
                                    top: 0,
                                    transform: `translateY(${rowIndex * ROW_HEIGHT}px)`,
                                }"
                            >
                                <div class="flex w-14 shrink-0 justify-center">
                                    <span v-if="player.medal" class="text-lg">
                                        <i
                                            class="fa-solid fa-medal !text-xl drop-shadow-[0_1px_1px_rgba(0,0,0,0.25)]"
                                            :class="medalColors[player.medal]"
                                        ></i>
                                    </span>
                                    <span v-else class="text-sm font-bold text-gray-900">{{ player.rank }}º</span>
                                </div>

                                <div class="flex w-16 shrink-0 justify-center overflow-hidden">
                                    <PlayerPhoto
                                        :src="player.photo_front"
                                        :initial="player.initial"
                                        :alt="player.name"
                                        size="sm"
                                    />
                                </div>

                                <div class="min-w-0 flex-1 truncate px-2 text-sm font-medium text-gray-900">
                                    {{ player.name }}
                                </div>

                                <div class="w-20 shrink-0 text-center text-sm font-bold text-gray-900">
                                    {{ player.total_points }}/{{ player.games_played }}
                                </div>
                            </div>
                        </div>

                        <p v-else class="p-6 text-center text-sm text-gray-500">
                            Nenhum jogo finalizado ainda.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.timeline-row :deep(img) {
    max-height: 2.5rem;
    padding-top: 0;
}
</style>
