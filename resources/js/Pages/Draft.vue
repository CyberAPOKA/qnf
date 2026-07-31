<script setup>
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DraftStatusCard from '@/Components/Game/DraftStatusCard.vue';
import TeamsBoard from '@/Components/Game/TeamsBoard.vue';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';
import RankingPlayerCard from '@/Components/RankingPlayerCard.vue';

import { router, useForm } from '@inertiajs/vue3';
import { useGameChannel } from '@/composables/useGameChannel';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    is_admin: Boolean,
});

const { store } = useGameChannel(props);
const pickForm = useForm({ user_id: null });

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

// --- Pool de jogadores (cards do ranking) ---
function mapForm(lastResults = []) {
    return lastResults.map((result) => (Number(result) === 1 ? 'win' : 'loss'));
}

function medalTheme(player) {
    if (player.position === 'goalkeeper') return 'default';

    const rank = Number(player.rank);
    if (rank === 1) return 'gold';
    if (rank === 2) return 'silver';
    if (rank === 3) return 'bronze';
    return 'default';
}

function toCardPlayer(player, index) {
    return {
        id: player.id,
        rank: Number(player.rank) || index + 1,
        name: player.name,
        photo_front: player.photo_front,
        initial: player.initial,
        points: Number(player.total_points) || 0,
        games: Number(player.games_played) || 0,
        movement: null,
        form: mapForm(player.last_results),
        theme: medalTheme(player),
        win_streak: Number(player.win_streak) || 0,
        isGoalkeeper: player.position === 'goalkeeper',
    };
}

const availablePool = computed(() =>
    (store.game?.available_players || []).map((player, index) => ({
        raw: player,
        card: toCardPlayer(player, index),
    })),
);

const onPickCardClick = (player) => {
    if (!canPickPlayer(player)) return;

    if (isDoublePick.value && isMyTurn.value) {
        toggleSelection(player);
        return;
    }

    if (isMyTurn.value) {
        confirmPick(player);
    }
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

const selectionOrder = (playerId) => {
    const idx = selectedIds.value.indexOf(playerId);
    return idx >= 0 ? idx + 1 : null;
};

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
            <div class="mx-auto max-w-4xl space-y-3">
                <DraftStatusCard :round-text="roundText" :pick-text="pickText" :status="store.game?.status || ''"
                    :is-my-turn="isMyTurn" :turn-captain-name="turnCaptainName" />

                <TeamsBoard :teams="store.game?.teams" title="Times" :show-header="false" />

                <section v-if="store.game?.status === 'drafting'">
                    <h3 class="pool__hint" :class="{ 'pool__hint--double': isDoublePick && isMyTurn }">
                        <template v-if="isDoublePick && isMyTurn">
                            <i class="fa-solid fa-people-arrows" aria-hidden="true" />
                            Escolha dupla! Selecione 2 jogadores
                            <i class="fa-solid fa-people-arrows" aria-hidden="true" />
                        </template>
                        <template v-else>
                            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true" />
                            Clique e confirme para escolher
                            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true" />
                        </template>
                    </h3>

                    <div class="ranking-board px-1 py-2 lg:px-4 lg:py-4">
                        <div class="ranking-board__list space-y-4">
                            <div v-for="{ raw, card } in availablePool" :key="raw.id"
                                class="ranking-board__item pick"
                                :class="{
                                    'ranking-board__item--podium': card.theme !== 'default',
                                    'pick--selected': selectionOrder(raw.id) != null,
                                    'pick--clickable': isMyTurn && canPickPlayer(raw),
                                    'pick--blocked': isMyTurn && !canPickPlayer(raw),
                                }"
                                @click="onPickCardClick(raw)">

                                <div class="pick__shell">
                                    <span
                                        v-if="selectionOrder(raw.id)"
                                        class="pick__order"
                                        aria-hidden="true"
                                    >
                                        {{ selectionOrder(raw.id) }}
                                    </span>
                                    <RankingPlayerCard class="pick__card" :player="card" />
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

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

<style scoped>
.pool__hint {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 0 0 10px;
    color: #fca5a5;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-align: center;
    text-transform: uppercase;
    text-shadow: 0 0 12px rgba(248, 113, 113, 0.35);
}

.pool__hint--double {
    color: #d8b4fe;
    text-shadow: 0 0 12px rgba(168, 85, 247, 0.45);
}

.pick {
    position: relative;
    transition: filter 160ms ease, opacity 160ms ease;
}

.pick__shell {
    --cut: 14px;

    position: relative;
    width: 100%;
}

.pick__card {
    width: 100%;
}

.pick--clickable {
    cursor: pointer;
}

.pick--selected .pick__shell::before {
    content: '';
    position: absolute;
    inset: -4px;
    z-index: 20;
    pointer-events: none;
    background: linear-gradient(135deg, #d8b4fe, #a855f7 40%, #7c3aed 70%, #c084fc);
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
    filter: drop-shadow(0 0 12px rgba(168, 85, 247, 0.65));
}

.pick--selected .pick__shell::after {
    content: '';
    position: absolute;
    inset: -1px;
    z-index: 21;
    pointer-events: none;
    background: transparent;
    box-shadow: inset 0 0 0 2px rgba(216, 180, 254, 0.95);
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

.pick--selected .pick__card {
    position: relative;
    z-index: 22;
}

.pick__order {
    position: absolute;
    top: -14px;
    left: 50%;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border: 2px solid #e9d5ff;
    border-radius: 999px;
    background: linear-gradient(180deg, #c084fc, #7c3aed);
    color: #faf5ff;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 0.95rem;
    font-weight: 800;
    line-height: 1;
    transform: translateX(-50%);
    box-shadow:
        0 0 14px rgba(168, 85, 247, 0.75),
        0 4px 10px rgba(0, 0, 0, 0.45);
    pointer-events: none;
}

.pick--blocked {
    opacity: 0.45;
    filter: saturate(0.55);
}

@media (min-width: 951px) {
    .pool__hint {
        font-size: 1.2rem;
    }

    .pick__shell {
        --cut: 14px;
    }

    .pick__order {
        top: -16px;
        width: 34px;
        height: 34px;
        font-size: 1.05rem;
    }
}

@media (max-width: 950px) {
    .pick__shell {
        --cut: 10px;
    }
}
</style>
