<script setup>
import { computed, ref, watch, onMounted, nextTick } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DraftStatusCard from '@/Components/Game/DraftStatusCard.vue';
import TeamCard from '@/Components/Game/TeamCard.vue';
import FireIcon from '@/Components/Game/FireIcon.vue';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';
import Button from 'primevue/button';

import { router, useForm } from '@inertiajs/vue3';
import { useGameChannel } from '@/composables/useGameChannel';
import { useFireParticles } from '@/composables/useFireParticles';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    is_admin: Boolean,
});

const { store } = useGameChannel(props);
const pickForm = useForm({ user_id: null });
const draftListWrapper = ref(null);
const { init: initFire } = useFireParticles();

function refreshFire() {
    nextTick(() => setTimeout(() => initFire(draftListWrapper.value, '.qnf-draft-fire'), 200));
}

onMounted(refreshFire);
watch(() => store.game?.available_players, refreshFire);

const turnCaptainName = computed(() => {
    const color = store.game?.turn_color;
    if (!color) return null;
    return store.game?.teams?.[color]?.captain?.name || null;
});

const isMyTurn = computed(() => {
    const color = store.game?.turn_color;
    if (!color) return false;
    return store.game?.teams?.[color]?.captain?.id === props.current_user_id;
});

const canPick = computed(() => {
    if (!store.game || store.game.status !== 'drafting') return false;
    return isMyTurn.value;
});

const isDoublePick = computed(() => store.game?.is_double_pick === true);

const myTeamPlayers = computed(() => {
    const color = store.game?.turn_color;
    if (!color || !isMyTurn.value) return [];
    return store.game?.teams?.[color]?.players || [];
});

const teamHasGoalkeeper = computed(() => {
    return myTeamPlayers.value.some(p => p.position === 'goalkeeper');
});

const teamLinePickCount = computed(() => {
    return myTeamPlayers.value.filter(p => p.position !== 'goalkeeper').length;
});

const canPickPlayer = (player) => {
    if (!isMyTurn.value) return false;

    // Already selected in double pick mode
    if (isDoublePick.value && isSelected(player.id)) return true;

    let gkCount = teamHasGoalkeeper.value ? 1 : 0;
    let lineCount = teamLinePickCount.value;

    // Account for already-selected players in double pick mode
    if (isDoublePick.value && selectedIds.value.length > 0) {
        const available = store.game?.available_players || [];
        for (const id of selectedIds.value) {
            if (id === player.id) continue;
            const sel = available.find(p => p.id === id);
            if (sel?.position === 'goalkeeper') gkCount++;
            else if (sel) lineCount++;
        }
    }

    if (gkCount >= 1 && player.position === 'goalkeeper') return false;
    if (lineCount >= 3 && player.position !== 'goalkeeper') return false;
    return true;
};

// --- Single pick mode ---
const playerToConfirm = ref(null);

const confirmPick = (player) => {
    playerToConfirm.value = player;
};

const cancelPick = () => {
    playerToConfirm.value = null;
};

const pickUser = () => {
    if (!store.game || !canPick.value || !playerToConfirm.value) return;
    pickForm.user_id = playerToConfirm.value.id;
    pickForm.post(route('games.pick', store.game.id), {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => { playerToConfirm.value = null; },
    });
};

// --- Double pick mode ---
const selectedIds = ref([]);
const showDoubleConfirm = ref(false);
const doublePickProcessing = ref(false);

const toggleSelection = (player) => {
    const idx = selectedIds.value.indexOf(player.id);
    if (idx >= 0) {
        selectedIds.value.splice(idx, 1);
    } else if (selectedIds.value.length < 2) {
        selectedIds.value.push(player.id);
    }
};

const isSelected = (playerId) => selectedIds.value.includes(playerId);

const selectedPlayers = computed(() => {
    const available = store.game?.available_players || [];
    return selectedIds.value.map(id => available.find(p => p.id === id)).filter(Boolean);
});

const confirmDoublePick = () => {
    if (selectedIds.value.length !== 2) return;
    showDoubleConfirm.value = true;
};

const cancelDoublePick = () => {
    showDoubleConfirm.value = false;
};

const submitDoublePick = () => {
    if (!store.game || selectedIds.value.length !== 2) return;
    doublePickProcessing.value = true;

    const gameId = store.game.id;
    const [firstId, secondId] = selectedIds.value;

    router.post(route('games.pick', gameId), { user_id: firstId }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            router.post(route('games.pick', gameId), { user_id: secondId }, {
                preserveScroll: true,
                preserveState: false,
                onFinish: () => {
                    doublePickProcessing.value = false;
                    showDoubleConfirm.value = false;
                    selectedIds.value = [];
                },
            });
        },
        onError: () => {
            doublePickProcessing.value = false;
            showDoubleConfirm.value = false;
        },
    });
};

// Reset selections when turn changes
watch(() => store.game?.turn_color, () => {
    selectedIds.value = [];
    showDoubleConfirm.value = false;
});

const roundText = computed(() => {
    const picksCount = store.game?.picks?.length || 0;
    return `Rodada ${Math.floor(picksCount / 3) + 1}`;
});

const pickText = computed(() => {
    const picksCount = store.game?.picks?.length || 0;
    return `Pick ${picksCount + 1}/12`;
});

watch(() => store.game?.status, (status) => {
    if (status === 'drafted') {
        router.visit(route('dashboard'));
    }
});
</script>

<template>
    <AppLayout title="Draft">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Draft dos Times</h2>
        </template>

        <div class="p-1 lg:p-4" :class="{ '!pb-24': isDoublePick && isMyTurn }">
            <div class="mx-auto max-w-6xl space-y-3">
                <DraftStatusCard :round-text="roundText" :pick-text="pickText" :status="store.game?.status || ''"
                    :is-my-turn="isMyTurn" :turn-captain-name="turnCaptainName" />

                <div class="grid grid-cols-3 gap-1 lg:gap-2">
                    <TeamCard color="green" :team="store.game?.teams?.green" />
                    <TeamCard color="yellow" :team="store.game?.teams?.yellow" />
                    <TeamCard color="blue" :team="store.game?.teams?.blue" />
                </div>

                <div v-if="store.game?.status === 'drafting'" class="rounded-xl bg-white p-1 lg:p-4 shadow">
                    <h3 class="text-lg font-bold text-center"
                        :class="isDoublePick && isMyTurn ? 'text-purple-600' : 'text-red-500'">
                        <template v-if="isDoublePick && isMyTurn">
                            <i class="fa-solid fa-people-arrows"></i>
                            Escolha dupla! Selecione 2 jogadores
                            <i class="fa-solid fa-people-arrows"></i>
                        </template>
                        <template v-else>
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Clique e confirme para escolher
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </template>
                    </h3>
                    <div class="flex justify-between flex-wrap mt-1">
                        <span class="text-purple-900">
                            <i class="fa-solid fa-ranking-star"></i>
                            Posição
                        </span>
                        <span class="text-blue-900">
                            <i class="fa-solid fa-futbol"></i>
                            Jogos
                        </span>
                        <span class="text-[#B8860B]">
                            <i class="fa-solid fa-trophy"></i>
                            Pontos
                        </span>
                    </div>
                    <ul ref="draftListWrapper" class="mt-2 space-y-2" style="position: relative;">
                        <li v-for="player in store.game?.available_players || []" :key="player.id"
                            class="flex flex-col gap-2 rounded-lg border border-gray-500 p-1 transition-colors shadow"
                            :class="[
                                isDoublePick && isMyTurn && isSelected(player.id)
                                    ? 'border-purple-500 bg-purple-50'
                                    : 'border-gray-100',
                                isDoublePick && isMyTurn && canPickPlayer(player) ? 'cursor-pointer' : '',
                                player.win_streak >= 3 ? 'qnf-draft-fire' : ''
                            ]"
                            @click="isDoublePick && isMyTurn && canPickPlayer(player) ? toggleSelection(player) : null">

                            <div class="flex items-center justify-between gap-1">
                                <!-- Photo -->
                                <div class="shrink-0">
                                    <PlayerPhoto :src="player.photo_front" :initial="player.initial" :alt="player.name"
                                        size="md" class="max-w-28 md:max-w-none"/>
                                </div>

                                <!-- Info -->
                                <div class="flex-1 min-w-0 flex flex-col justify-between">
                                    <div class="flex items-center">
                                        <FireIcon :streak="player.win_streak" />
                                        <p class="font-bold text-gray-900 truncate">{{ player.name }}</p>
                                        <PositionBadge :position="player.position" :label="player.position_label" />
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <div class="flex items-center text-purple-900">
                                            <i class="fa-solid fa-ranking-star"></i>
                                            <span v-if="player.rank != null && player.position !== 'goalkeeper'"
                                                class="font-bold">
                                                {{ player.rank }}º
                                            </span>
                                        </div>
                                        <div class="flex items-center text-blue-900">
                                            <i class="fa-solid fa-futbol"></i>
                                            <span class="font-bold">{{ player.games_played }}</span>
                                        </div>
                                        <div class="flex items-center text-[#B8860B]">
                                            <i class="fa-solid fa-trophy"></i>
                                            <span class="font-bold">{{ player.total_points }}</span>
                                        </div>
                                    </div>
                                    <div v-if="player.last_results?.length" class="flex items-center gap-1">
                                        <span v-for="(result, i) in player.last_results" :key="i">
                                            <i v-if="result === 1"
                                                class="fa-regular fa-circle-check text-green-600 text-xs"></i>
                                            <i v-else class="fa-regular fa-circle-xmark text-red-500 text-xs"></i>
                                        </span>
                                    </div>
                                </div>

                                <!-- Action: single pick button OR double pick checkbox -->
                                <div class="shrink-0">
                                    <template v-if="isDoublePick && isMyTurn">
                                        <div v-if="canPickPlayer(player)"
                                            class="flex h-6 w-6 items-center justify-center rounded border-2 transition-colors"
                                            :class="isSelected(player.id)
                                                ? 'border-purple-600 bg-purple-600 text-white'
                                                : 'border-gray-300 bg-white'">
                                            <i v-if="isSelected(player.id)" class="fa-solid fa-check text-xs"></i>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <Button v-if="canPickPlayer(player)" severity="contrast"
                                            :disabled="pickForm.processing" @click="confirmPick(player)">
                                            Escolher
                                        </Button>
                                    </template>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- Fixed bottom bar for double pick -->
        <Teleport to="body">
            <div v-if="isDoublePick && isMyTurn && store.game?.status === 'drafting'"
                class="fixed bottom-0 inset-x-0 z-50 border-t bg-white p-4 shadow-[0_-4px_12px_rgba(0,0,0,0.1)]">
                <div class="mx-auto max-w-6xl flex items-center justify-between gap-4">
                    <div class="text-sm text-gray-600">
                        <span class="font-semibold">{{ selectedIds.length }}/2</span> selecionados
                        <template v-if="selectedPlayers.length">
                            <span class="hidden sm:inline"> —
                                <span v-for="(p, i) in selectedPlayers" :key="p.id">
                                    <strong>{{ p.name }}</strong><span v-if="i === 0 && selectedPlayers.length === 2">,
                                    </span>
                                </span>
                            </span>
                        </template>
                    </div>
                    <PrimaryButton :disabled="selectedIds.length !== 2 || doublePickProcessing"
                        @click="confirmDoublePick" class="bg-purple-600 hover:bg-purple-700 px-6 py-3 text-base">
                        <i class="fa-solid fa-check-double mr-2"></i>
                        Escolher 2
                    </PrimaryButton>
                </div>
            </div>
        </Teleport>

        <!-- Single pick confirmation modal -->
        <ConfirmationModal :show="playerToConfirm !== null" @close="cancelPick">
            <template #title>Confirmar escolha</template>
            <template #content>
                <div class="flex items-center gap-3">
                    <div class="shrink-0">
                        <PlayerPhoto :src="playerToConfirm?.photo_front" :initial="playerToConfirm?.initial"
                            :alt="playerToConfirm?.name" size="md" />
                    </div>
                    <p class="text-gray-900">Deseja escolher
                        <strong class="text-base">
                            {{ playerToConfirm?.name }}
                        </strong>?
                    </p>
                </div>
            </template>
            <template #footer>
                <SecondaryButton @click="cancelPick">Cancelar</SecondaryButton>
                <PrimaryButton class="ms-3" :disabled="pickForm.processing" @click="pickUser">
                    Confirmar
                </PrimaryButton>
            </template>
        </ConfirmationModal>

        <!-- Double pick confirmation modal -->
        <ConfirmationModal :show="showDoubleConfirm" @close="cancelDoublePick">
            <template #title>Confirmar escolha dupla</template>
            <template #content>
                <p class="text-gray-900 mb-4">Deseja escolher estes 2 jogadores?</p>
                <div class="grid grid-cols-2 gap-2">
                    <div v-for="player in selectedPlayers" :key="player.id"
                        class="flex flex-col items-center gap-2 rounded-lg border border-purple-200 bg-purple-50 p-3">
                        <div class="shrink-0">
                            <PlayerPhoto :src="player.photo_front" :initial="player.initial" :alt="player.name"
                                size="md" />
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">{{ player.name }}</p>
                            <PositionBadge :position="player.position" :label="player.position_label" />
                        </div>
                    </div>
                </div>
            </template>
            <template #footer>
                <SecondaryButton @click="cancelDoublePick">Cancelar</SecondaryButton>
                <PrimaryButton class="ms-3 bg-purple-600 hover:bg-purple-700" :disabled="doublePickProcessing"
                    @click="submitDoublePick">
                    <i v-if="doublePickProcessing" class="fa-solid fa-spinner fa-spin mr-2"></i>
                    Confirmar escolha dupla
                </PrimaryButton>
            </template>
        </ConfirmationModal>
    </AppLayout>
</template>

<style>
.qnf-draft-fire {
    animation: qnfDraftGlow 2s ease-in-out infinite;
}

@keyframes qnfDraftGlow {
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
    .qnf-draft-fire {
        animation: none !important;
        box-shadow: 0 0 12px 3px rgba(255, 100, 0, 0.4);
    }
}
</style>
