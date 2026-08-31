<script setup>
import { ref, computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import GameStatusCard from '@/Components/Game/GameStatusCard.vue';
import MarketCountdown from '@/Components/Game/MarketCountdown.vue';
import PlayerActionsPanel from '@/Components/Game/PlayerActionsPanel.vue';
import SubscribedPlayersCard from '@/Components/Game/SubscribedPlayersCard.vue';
import TeamsBoard from '@/Components/Game/TeamsBoard.vue';
import RankingCard from '@/Components/Game/RankingCard.vue';
import WinsRankingCard from '@/Components/Game/WinsRankingCard.vue';
import PredictionCard from '@/Components/Game/PredictionCard.vue';
import PixPaymentCard from '@/Components/Game/PixPaymentCard.vue';
import AddPlayersCard from '@/Components/Game/AddPlayersCard.vue';
import AdminTeamTools from '@/Components/Game/AdminTeamTools.vue';
import AdminMediaTools from '@/Components/Game/AdminMediaTools.vue';
import AdminVoiceMessageCard from '@/Components/Game/AdminVoiceMessageCard.vue';
import WeekTeamGenerator from '@/Components/Game/WeekTeamGenerator.vue';
import WeekTeamCard from '@/Components/Game/WeekTeamCard.vue';
import AddGuestModal from '@/Components/Game/AddGuestModal.vue';
import PlayerFormModal from '@/Components/Game/PlayerFormModal.vue';
import TabNavigation from '@/Components/TabNavigation.vue';
import { useForm } from '@inertiajs/vue3';
import { useGameChannel } from '@/composables/useGameChannel';
import { useDraftRedirect } from '@/composables/useDraftRedirect';
import { useCountdown } from '@/composables/useCountdown';
import axios from 'axios';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    is_admin: Boolean,
    is_goalkeeper: Boolean,
    dropped_out: Boolean,
    waitlist_position: Number,
    ranking: Array,
    wins_ranking: Array,
    week_teams: Array,
    payment: Object,
    prediction: Object,
    rounds: Array,
    // Admin-only props
    all_users: Array,
    can_enter_scores: Boolean,
    payments: Array,
});

const { store } = useGameChannel(props);
useDraftRedirect();

// --- Tab navigation ---
const activeTab = ref('game');
const tabs = [
    { value: 'game', label: 'Jogo', icon: 'fa-solid fa-shield-halved' },
    { value: 'ranking', label: 'Ranking', icon: 'fa-solid fa-ranking-star' },
    { value: 'wins', label: 'Vitórias', icon: 'fa-solid fa-trophy' },
    { value: 'prediction', label: 'Previsão', icon: 'fa-solid fa-brain' },
];

// --- Round selector ---
const currentRound = ref(props.game?.round || null);
const isCurrentRound = computed(() => currentRound.value === props.game?.round);
const loadingRound = ref(false);

// Round-specific data (overrides when viewing past rounds)
const roundGame = ref(null);
const roundRanking = ref(null);
const roundWinsRanking = ref(null);
const roundPrediction = ref(null);
const roundWeekTeams = ref(null);
const roundPayments = ref(null);
const roundCanEnterScores = ref(null);

// Effective data (current round uses props, other rounds use fetched data)
const effectiveGame = computed(() => isCurrentRound.value ? store.game : roundGame.value);
const effectiveRanking = computed(() => isCurrentRound.value ? props.ranking : roundRanking.value);
const effectiveWinsRanking = computed(() => isCurrentRound.value ? props.wins_ranking : roundWinsRanking.value);
const effectivePrediction = computed(() => isCurrentRound.value ? props.prediction : roundPrediction.value);
const effectiveWeekTeams = computed(() => isCurrentRound.value ? props.week_teams : roundWeekTeams.value);
const effectivePayments = computed(() => isCurrentRound.value ? props.payments : roundPayments.value);
const effectiveCanEnterScores = computed(() => isCurrentRound.value ? props.can_enter_scores : roundCanEnterScores.value);

watch(currentRound, async (newRound) => {
    if (newRound === props.game?.round) return;

    loadingRound.value = true;
    try {
        const { data } = await axios.get(route('api.round-data'), { params: { round: newRound } });
        roundGame.value = data.game;
        roundRanking.value = data.ranking;
        roundWinsRanking.value = data.wins_ranking;
        roundPrediction.value = data.prediction;
        roundWeekTeams.value = data.week_teams;
        roundPayments.value = data.payments ?? null;
        roundCanEnterScores.value = data.can_enter_scores ?? false;
    } catch (e) {
        console.error('Failed to load round data', e);
    } finally {
        loadingRound.value = false;
    }
});

// --- Player actions ---
const form = useForm({});
const waitlistForm = useForm({});
const quitForm = useForm({});
const showQuitModal = ref(false);

const joined = computed(() => {
    return !!store.game?.players?.some((player) => player.id === props.current_user_id);
});

const linePlayerCount = computed(() => {
    return (store.game?.players || []).filter((p) => p.position !== 'goalkeeper').length;
});

const canJoin = computed(() => {
    if (!isCurrentRound.value || props.is_admin || props.is_goalkeeper || props.dropped_out) return false;
    return store.game?.status === 'open' && !joined.value && linePlayerCount.value < 12;
});

const canJoinWaitlist = computed(() => {
    if (!isCurrentRound.value || props.is_admin || props.is_goalkeeper || props.dropped_out || props.waitlist_position) return false;
    return ['full', 'drafting', 'drafted'].includes(store.game?.status) && !joined.value;
});

const canQuit = computed(() => {
    if (!isCurrentRound.value || props.waitlist_position) return false;
    return joined.value && ['open', 'full', 'drafted'].includes(store.game?.status);
});

const joinGame = () => {
    if (!store.game) return;
    form.post(route('games.join', store.game.id), { preserveScroll: true, preserveState: false });
};

const joinWaitlist = () => {
    if (!store.game) return;
    waitlistForm.post(route('games.join-waitlist', store.game.id), { preserveScroll: true, preserveState: false });
};

const confirmQuit = () => {
    if (!store.game) return;
    quitForm.post(route('games.quit', store.game.id), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => { showQuitModal.value = false; },
    });
};

const { countdown } = useCountdown(() => store.game?.opens_at);

const showMarketCountdown = computed(
    () => effectiveGame.value?.status === 'scheduled' && isCurrentRound.value,
);

// --- Admin actions ---
const selectedUsers = ref([]);
const selectedGuests = ref([]);
const addPlayersForm = useForm({ user_ids: [] });
const addGuestsForm = useForm({ user_ids: [] });
const guestModal = ref(null);
const playerModal = ref(null);

const availableUsers = computed(() => {
    const joinedIds = (store.game?.players || []).map((p) => p.id);
    return (props.all_users || []).filter((u) => !joinedIds.includes(u.id) && !u.guest);
});

const availableGuests = computed(() => {
    const joinedIds = (store.game?.players || []).map((p) => p.id);
    return (props.all_users || []).filter((u) => !joinedIds.includes(u.id) && u.guest);
});

const canAddPlayers = computed(() => {
    return isCurrentRound.value && ['scheduled', 'open', 'full'].includes(store.game?.status);
});

const goalkeeperCount = computed(() => {
    return (store.game?.players || []).filter((p) => p.position === 'goalkeeper').length;
});

const addPlayers = () => {
    if (!store.game || !selectedUsers.value.length) return;
    addPlayersForm.user_ids = selectedUsers.value.map((u) => u.id);
    addPlayersForm.post(route('games.add-players', store.game.id), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => { selectedUsers.value = []; },
    });
};

const addGuests = () => {
    if (!store.game || !selectedGuests.value.length) return;
    addGuestsForm.user_ids = selectedGuests.value.map((u) => u.id);
    addGuestsForm.post(route('games.add-players', store.game.id), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => { selectedGuests.value = []; },
    });
};

const teamPlayerIds = computed(() => {
    const game = effectiveGame.value;
    const teams = game?.teams;
    if (!teams) return new Set();
    const ids = new Set();
    for (const color of ['green', 'yellow', 'blue']) {
        const t = teams[color];
        if (t?.captain) ids.add(t.captain.id);
        for (const p of t?.players || []) ids.add(p.id);
    }
    return ids;
});

const availableForTeam = computed(() => {
    return (props.all_users || []).filter((u) => !teamPlayerIds.value.has(u.id));
});

const teamsBoardTitle = computed(() =>
    effectiveGame.value?.status === 'done' ? 'Placar Final' : 'Times da Rodada',
);

const showTeams = computed(
    () => ['drafted', 'done'].includes(effectiveGame.value?.status),
);
</script>

<template>
    <AppLayout title="Dashboard">
        <!-- <template #header>
            <TitleCard />
        </template> -->

        <div class="px-1 py-2 sm:p-2 lg:p-2 pb-24 md:pb-4">
            <div class="mx-auto space-y-4"
                :class="activeTab === 'ranking' || activeTab === 'wins' ? 'max-w-3xl' : 'max-w-3xl'">

                <!-- Tab Navigation -->
                <TabNavigation v-model="activeTab" :tabs="tabs" />

                <!-- Loading overlay -->
                <div v-if="loadingRound" class="flex justify-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-indigo-600"></i>
                </div>

                <!-- Tab: Jogo -->
                <template v-if="activeTab === 'game' && !loadingRound">

                    <GameStatusCard :status="effectiveGame?.status" :status-label="effectiveGame?.status_label"
                        :players-count="effectiveGame?.players_count" :round="effectiveGame?.round"
                        :rounds="rounds || []" @update:round="currentRound = $event">

                        <template v-if="is_admin" #details>
                            <p class="mt-1 text-xs font-semibold uppercase tracking-widest text-slate-400">
                                Linha <span class="text-slate-200">{{ linePlayerCount }}/12</span>
                                ·
                                Goleiros
                                <span :class="goalkeeperCount < 3 ? 'text-red-400' : 'text-green-400'">
                                    {{ goalkeeperCount }}/3
                                </span>
                            </p>
                        </template>

                        <template #actions>
                            <MarketCountdown v-if="showMarketCountdown" :value="countdown" />

                            <PlayerActionsPanel v-else :game="effectiveGame" :is-current-round="isCurrentRound"
                                :is-goalkeeper="is_goalkeeper" :dropped-out="dropped_out"
                                :waitlist-position="waitlist_position" :can-join="canJoin"
                                :can-join-waitlist="canJoinWaitlist" :can-quit="canQuit" :joining="form.processing"
                                :joining-waitlist="waitlistForm.processing" @join="joinGame"
                                @join-waitlist="joinWaitlist" @quit="showQuitModal = true" />
                        </template>

                        <template #footer>
                            <p v-if="effectiveGame?.status === 'full'"
                                class="mt-3 text-sm font-semibold uppercase tracking-widest text-red-400">
                                Lista fechada
                            </p>
                        </template>
                    </GameStatusCard>

                    <WeekTeamCard :teams="effectiveWeekTeams || []" />

                    <!-- Admin: Regenerate week team -->
                    <WeekTeamGenerator v-if="is_admin && effectiveGame?.status === 'done'"
                        :game-id="effectiveGame?.id" />

                    <!-- Admin: Add players -->
                    <template v-if="is_admin && canAddPlayers">
                        <AddPlayersCard v-model="selectedUsers" title="Adicionar jogadores" :options="availableUsers"
                            placeholder="Selecione jogadores" create-label="Criar jogador"
                            :processing="addPlayersForm.processing" @create="playerModal?.openCreate()"
                            @submit="addPlayers" />

                        <AddPlayersCard v-model="selectedGuests" title="Adicionar convidados" :options="availableGuests"
                            placeholder="Selecione convidados" create-label="Criar convidado"
                            badge-class="bg-orange-100 text-orange-700"
                            submit-class="bg-orange-500 hover:bg-orange-600 focus:bg-orange-600"
                            :processing="addGuestsForm.processing" @create="guestModal?.open()" @submit="addGuests" />
                    </template>

                    <!-- Player list (before draft) -->
                    <SubscribedPlayersCard v-if="!showTeams" :players="effectiveGame?.players || []"
                        :game-id="effectiveGame?.id" :editable="is_admin && isCurrentRound"
                        :available-users="all_users || []" />

                    <!-- Teams (after draft) -->
                    <template v-else>
                        <TeamsBoard :teams="effectiveGame?.teams" :title="teamsBoardTitle"
                            :status-label="effectiveGame?.status_label" :editable="is_admin && isCurrentRound"
                            :game-id="effectiveGame?.id" :available-players="availableForTeam" />

                        <AdminTeamTools v-if="is_admin" :game="effectiveGame" :payments="effectivePayments || []"
                            :is-current-round="isCurrentRound" />

                        <PixPaymentCard
                            v-if="!is_admin && !is_goalkeeper && isCurrentRound && !dropped_out"
                            :payment="payment"
                            :game-id="effectiveGame?.id"
                        />
                    </template>
                    
                    <AdminVoiceMessageCard v-if="is_admin" />

                    <!-- Admin: extra tools (current round only) -->
                    <AdminMediaTools v-if="is_admin && isCurrentRound" />
                </template>

                <!-- Tab: Ranking -->
                <template v-if="activeTab === 'ranking' && !loadingRound">
                    <RankingCard :ranking="effectiveRanking || []" />
                </template>

                <!-- Tab: Vitórias -->
                <template v-if="activeTab === 'wins' && !loadingRound">
                    <WinsRankingCard :ranking="effectiveWinsRanking || []" />
                </template>

                <!-- Tab: Previsão -->
                <template v-if="activeTab === 'prediction' && !loadingRound">
                    <PredictionCard :prediction="effectivePrediction" />
                </template>
            </div>
        </div>

        <!-- Quit confirmation modal -->
        <ConfirmationModal :show="showQuitModal" @close="showQuitModal = false">
            <template #title>Desistir do jogo</template>
            <template #content>
                Tem certeza que deseja desistir? <strong>Você não poderá se inscrever novamente nesta rodada.</strong>
            </template>
            <template #footer>
                <SecondaryButton @click="showQuitModal = false">Cancelar</SecondaryButton>
                <PrimaryButton class="ms-3 !bg-red-600 hover:!bg-red-500" :disabled="quitForm.processing"
                    @click="confirmQuit">
                    Sim, desistir
                </PrimaryButton>
            </template>
        </ConfirmationModal>

        <!-- Admin modals -->
        <template v-if="is_admin">
            <PlayerFormModal ref="playerModal" />
            <AddGuestModal ref="guestModal" :game-id="store.game?.id" />
        </template>
    </AppLayout>
</template>