<script setup>
import { ref, computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import GameStatusCard from '@/Components/Game/GameStatusCard.vue';
import PlayerListCard from '@/Components/Game/PlayerListCard.vue';
import TeamCard from '@/Components/Game/TeamCard.vue';
import RankingCard from '@/Components/Game/RankingCard.vue';
import WinsRankingCard from '@/Components/Game/WinsRankingCard.vue';
import PredictionCard from '@/Components/Game/PredictionCard.vue';
import PixPaymentCard from '@/Components/Game/PixPaymentCard.vue';
import PaymentManagementCard from '@/Components/Game/PaymentManagementCard.vue';
import ScoreEntryCard from '@/Components/Game/ScoreEntryCard.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import WeekTeamCard from '@/Components/Game/WeekTeamCard.vue';
import AddGuestModal from '@/Components/Game/AddGuestModal.vue';
import AddPlayerModal from '@/Components/Game/AddPlayerModal.vue';
import FuturisticButton from '@/Components/FuturisticButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useGameChannel } from '@/composables/useGameChannel';
import { useDraftRedirect } from '@/composables/useDraftRedirect';
import { useCountdown } from '@/composables/useCountdown';
import Button from 'primevue/button';
import MultiSelect from 'primevue/multiselect';
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
    week_team_images: Array,
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
    { value: 'game', label: 'Jogo', icon: 'fa-solid fa-futbol' },
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
const roundWeekTeamImages = ref(null);
const roundPayments = ref(null);
const roundCanEnterScores = ref(null);

// Effective data (current round uses props, other rounds use fetched data)
const effectiveGame = computed(() => isCurrentRound.value ? store.game : roundGame.value);
const effectiveRanking = computed(() => isCurrentRound.value ? props.ranking : roundRanking.value);
const effectiveWinsRanking = computed(() => isCurrentRound.value ? props.wins_ranking : roundWinsRanking.value);
const effectivePrediction = computed(() => isCurrentRound.value ? props.prediction : roundPrediction.value);
const effectiveWeekTeamImages = computed(() => isCurrentRound.value ? props.week_team_images : roundWeekTeamImages.value);
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
        roundWeekTeamImages.value = data.week_team_images;
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
    if (!isCurrentRound.value || props.is_goalkeeper || props.dropped_out) return false;
    return store.game?.status === 'open' && !joined.value && linePlayerCount.value < 12;
});

const canJoinWaitlist = computed(() => {
    if (!isCurrentRound.value || props.is_goalkeeper || props.dropped_out || props.waitlist_position) return false;
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

const copyTeamsLabel = ref('Copiar Times');
const copyTeams = async () => {
    const msg = effectiveGame.value?.whatsapp_message;
    if (!msg) return;
    try {
        if (navigator.clipboard) {
            await navigator.clipboard.writeText(msg);
        } else {
            const ta = document.createElement('textarea');
            ta.value = msg;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        copyTeamsLabel.value = 'Copiado!';
    } catch {
        copyTeamsLabel.value = 'Erro ao copiar';
    }
    setTimeout(() => { copyTeamsLabel.value = 'Copiar Times'; }, 2000);
};

const whatsappTestLabel = ref('Teste WhatsApp');
const whatsappTesting = ref(false);

const sendWhatsAppTest = async () => {
    if (whatsappTesting.value) return;
    whatsappTesting.value = true;
    whatsappTestLabel.value = 'Enviando...';
    try {
        await axios.post(route('api.whatsapp.send-test'));
        whatsappTestLabel.value = 'Enviado!';
    } catch (e) {
        const msg = e.response?.data?.error || 'Erro';
        whatsappTestLabel.value = msg;
    } finally {
        setTimeout(() => {
            whatsappTestLabel.value = 'Teste WhatsApp';
            whatsappTesting.value = false;
        }, 3000);
    }
};

const captainsLoading = ref(false);
const captainsImage = ref(null);

const generateCaptains = async () => {
    if (captainsLoading.value) return;
    captainsLoading.value = true;
    try {
        const { data } = await axios.post(route('api.captains.generate'));
        captainsImage.value = data.image + '?t=' + Date.now();
    } catch (e) {
        console.error('Failed to generate captains image', e);
    } finally {
        captainsLoading.value = false;
    }
};

const lineupsLoading = ref(false);
const lineupsImage = ref(null);

const generateLineups = async () => {
    if (lineupsLoading.value) return;
    lineupsLoading.value = true;
    try {
        const { data } = await axios.post(route('api.lineups.generate'));
        lineupsImage.value = data.image + '?t=' + Date.now();
    } catch (e) {
        console.error('Failed to generate lineups image', e);
    } finally {
        lineupsLoading.value = false;
    }
};

const paymentsLoading = ref(false);
const paymentsResult = ref(null);

const createPayments = async () => {
    if (paymentsLoading.value) return;
    paymentsLoading.value = true;
    paymentsResult.value = null;
    try {
        const { data } = await axios.post(route('api.payments.create-all'));
        paymentsResult.value = data.message;
    } catch (e) {
        paymentsResult.value = e.response?.data?.error || 'Erro ao criar pagamentos';
    } finally {
        paymentsLoading.value = false;
        setTimeout(() => { paymentsResult.value = null; }, 4000);
    }
};

// --- Week team regeneration ---
const weekTeamLoading = ref(false);
const weekTeamResult = ref(null);
const regeneratedImages = ref([]);

const allWeekTeamsLoading = ref(false);
const allWeekTeamsResult = ref(null);

const regenerateAllWeekTeams = async () => {
    if (allWeekTeamsLoading.value) return;
    if (!confirm('Gerar imagens de Time da Semana para TODAS as rodadas finalizadas? Pode demorar.')) return;
    allWeekTeamsLoading.value = true;
    allWeekTeamsResult.value = null;
    try {
        const { data } = await axios.post(route('api.games.regenerate-all-week-teams'));
        allWeekTeamsResult.value = data.message;
    } catch (e) {
        allWeekTeamsResult.value = e.response?.data?.error || 'Erro ao gerar times da semana';
    } finally {
        allWeekTeamsLoading.value = false;
        setTimeout(() => { allWeekTeamsResult.value = null; }, 6000);
    }
};

const regenerateWeekTeam = async () => {
    const game = effectiveGame.value;
    if (!game?.id || weekTeamLoading.value) return;
    weekTeamLoading.value = true;
    weekTeamResult.value = null;
    try {
        const { data } = await axios.post(route('api.games.regenerate-week-team', game.id));
        regeneratedImages.value = data.images || [];
        weekTeamResult.value = 'Time da semana gerado!';
    } catch (e) {
        weekTeamResult.value = e.response?.data?.error || 'Erro ao gerar time da semana';
    } finally {
        weekTeamLoading.value = false;
        setTimeout(() => { weekTeamResult.value = null; }, 4000);
    }
};
</script>

<template>
    <AppLayout title="">
        <template #header>
            <TitleCard />
        </template>

        <div class="px-1 py-2 sm:p-2 lg:p-4 pb-24 md:pb-4">
            <div class="mx-auto max-w-3xl space-y-4">

                <GameStatusCard :status="effectiveGame?.status" :status-label="effectiveGame?.status_label"
                    :players-count="effectiveGame?.players_count" :round="effectiveGame?.round" :rounds="rounds || []"
                    @update:round="currentRound = $event">

                    <!-- Admin details -->
                    <template #details v-if="is_admin">
                        <div class="mt-1 text-sm text-gray-500">
                            Linha: <span class="font-semibold">{{ linePlayerCount }}/12</span>
                            · Goleiros:
                            <span class="font-semibold"
                                :class="goalkeeperCount < 3 ? 'text-red-600' : 'text-green-600'">
                                {{ goalkeeperCount }}/3
                            </span>
                        </div>
                    </template>

                    <template #actions>
                        <!-- Countdown for scheduled games -->
                        <div v-if="effectiveGame?.status === 'scheduled' && isCurrentRound" class="text-center">
                            <p class="font-bold text-xl text-gray-900">MERCADO EM</p>
                            <p v-if="countdown" class="text-3xl font-bold text-blue-900 tabular-nums">
                                {{ countdown }}
                            </p>
                        </div>

                        <!-- Player actions (only for current round) -->
                        <template v-else-if="!is_goalkeeper && isCurrentRound">
                            <p v-if="dropped_out" class="font-medium text-red-600">
                                Você desistiu desta rodada!
                                <i class="fa-regular fa-face-sad-tear"></i>
                            </p>

                            <template v-else-if="waitlist_position">
                                <p class="font-medium text-amber-600">
                                    <i class="fa-solid fa-clock"></i>
                                    Você está na fila de espera ({{ waitlist_position }}º)
                                </p>
                            </template>

                            <template v-else>
                                <FuturisticButton v-if="canJoin" label="Eu quero jogar"
                                    class="w-full justify-center py-3 text-base" :disabled="form.processing"
                                    @click="joinGame" />

                                <PrimaryButton v-if="canJoinWaitlist"
                                    class="w-full justify-center py-3 text-base !bg-amber-500 hover:!bg-amber-600 focus:!bg-amber-600"
                                    :disabled="waitlistForm.processing" @click="joinWaitlist">
                                    Entrar na fila de espera
                                </PrimaryButton>

                                <Button v-if="canQuit" @click="showQuitModal = true" severity="danger" fluid>
                                    Eu quero desistir
                                </Button>
                            </template>
                        </template>

                        <!-- Draft link -->
                        <Link v-if="effectiveGame?.status === 'drafting' && isCurrentRound"
                            class="inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-3 text-base font-semibold text-white hover:bg-indigo-700"
                            :href="route('games.draft', effectiveGame.id)">
                            Ir para Draft
                        </Link>
                    </template>

                    <template #footer>
                        <p v-if="effectiveGame?.status === 'full'" class="mt-3 text-sm font-medium text-red-600">
                            Lista fechada
                        </p>
                    </template>
                </GameStatusCard>

                <!-- Tab Navigation: fixa no rodapé (mobile) / inline (desktop) -->
                <div
                    class="fixed bottom-0 inset-x-0 z-40 flex bg-white border-t border-gray-200 shadow-[0_-4px_12px_rgba(0,0,0,0.08)] pb-[env(safe-area-inset-bottom)] md:static md:z-auto md:rounded-xl md:border-0 md:shadow md:pb-0 overflow-hidden">
                    <button v-for="tab in tabs" :key="tab.value" @click="activeTab = tab.value"
                        class="flex-1 flex flex-col items-center gap-1 py-3 px-2 text-xs font-semibold transition-colors"
                        :class="activeTab === tab.value
                            ? 'bg-indigo-600 text-white'
                            : 'text-gray-600 hover:bg-gray-50'">
                        <i :class="tab.icon" class="text-base"></i>
                        <span>{{ tab.label }}</span>
                    </button>
                </div>

                <!-- Loading overlay -->
                <div v-if="loadingRound" class="flex justify-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-indigo-600"></i>
                </div>

                <!-- Tab: Jogo -->
                <template v-if="activeTab === 'game' && !loadingRound">
                    <WeekTeamCard :images="effectiveWeekTeamImages || []" />

                    <!-- Admin: Regenerate week team button -->
                    <div v-if="is_admin && effectiveGame?.status === 'done'" class="flex flex-col items-center gap-2">
                        <button @click="regenerateWeekTeam" :disabled="weekTeamLoading"
                            class="w-full rounded-md bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-600 disabled:opacity-50 transition">
                            <i class="fa-solid fa-image mr-1.5"></i>
                            {{ weekTeamLoading ? 'Gerando...' : 'GERAR TIME DA SEMANA' }}
                        </button>
                        <p v-if="weekTeamResult" class="text-sm font-medium text-gray-700">{{ weekTeamResult }}</p>
                        <div v-if="regeneratedImages.length" class="space-y-2 w-full">
                            <img v-for="(src, i) in regeneratedImages" :key="i" :src="src" alt="Time da Semana"
                                class="w-full rounded-lg shadow" />
                        </div>
                    </div>

                    <!-- Admin: Add players -->
                    <template v-if="is_admin && canAddPlayers">
                        <div class="rounded-xl bg-white p-2 lg:p-4 shadow">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-semibold text-gray-900">Adicionar jogadores</h3>
                                <SecondaryButton class="text-xs" @click="playerModal?.open()">
                                    Criar jogador
                                </SecondaryButton>
                            </div>
                            <div class="mt-3 space-y-3">
                                <MultiSelect v-model="selectedUsers" :options="availableUsers" optionLabel="name"
                                    placeholder="Selecione jogadores" filter :maxSelectedLabels="3" class="w-full">
                                    <template #option="{ option }">
                                        <div class="flex items-center gap-2">
                                            <span>{{ option.name }}</span>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700">
                                                {{ option.position_label }}
                                            </span>
                                        </div>
                                    </template>
                                </MultiSelect>
                                <PrimaryButton class="w-full justify-center py-3 text-base"
                                    :disabled="addPlayersForm.processing || !selectedUsers.length" @click="addPlayers">
                                    Adicionar selecionados
                                </PrimaryButton>
                            </div>
                        </div>

                        <div class="rounded-xl bg-white p-2 lg:p-4 shadow">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-semibold text-gray-900">Adicionar convidados</h3>
                                <SecondaryButton class="text-xs" @click="guestModal?.open()">
                                    Criar convidado
                                </SecondaryButton>
                            </div>
                            <div class="mt-3 space-y-3">
                                <MultiSelect v-model="selectedGuests" :options="availableGuests" optionLabel="name"
                                    placeholder="Selecione convidados" filter :maxSelectedLabels="3" class="w-full">
                                    <template #option="{ option }">
                                        <div class="flex items-center gap-2">
                                            <span>{{ option.name }}</span>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-semibold bg-orange-100 text-orange-700">
                                                {{ option.position_label }}
                                            </span>
                                        </div>
                                    </template>
                                </MultiSelect>
                                <PrimaryButton
                                    class="w-full justify-center py-3 text-base bg-orange-500 hover:bg-orange-600 focus:bg-orange-600"
                                    :disabled="addGuestsForm.processing || !selectedGuests.length" @click="addGuests">
                                    Adicionar selecionados
                                </PrimaryButton>
                            </div>
                        </div>
                    </template>

                    <!-- Player list (before draft) -->
                    <PlayerListCard v-if="!['drafted', 'done'].includes(effectiveGame?.status)"
                        :players="effectiveGame?.players || []" :game-id="effectiveGame?.id"
                        :editable="is_admin && isCurrentRound" />

                    <!-- Teams (after draft) -->
                    <template v-if="['drafted', 'done'].includes(effectiveGame?.status)">
                        <div class="grid grid-cols-3 gap-1 lg:gap-2">
                            <TeamCard color="green" :team="effectiveGame?.teams?.green"
                                :editable="is_admin && isCurrentRound" :game-id="effectiveGame?.id"
                                :available-players="availableForTeam" />
                            <TeamCard color="yellow" :team="effectiveGame?.teams?.yellow"
                                :editable="is_admin && isCurrentRound" :game-id="effectiveGame?.id"
                                :available-players="availableForTeam" />
                            <TeamCard color="blue" :team="effectiveGame?.teams?.blue"
                                :editable="is_admin && isCurrentRound" :game-id="effectiveGame?.id"
                                :available-players="availableForTeam" />
                        </div>

                        <!-- Admin: copy teams, scores, payments -->
                        <template v-if="is_admin">
                            <button v-if="effectiveGame?.whatsapp_message" @click="copyTeams"
                                class="w-full rounded-md border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 transition">
                                <i class="fa-regular fa-copy mr-1.5"></i>
                                {{ copyTeamsLabel }}
                            </button>
                            <ScoreEntryCard v-if="isCurrentRound" :game-id="effectiveGame.id"
                                :teams="effectiveGame.teams" />
                            <PaymentManagementCard :payments="effectivePayments || []" />

                            <div v-if="isCurrentRound" class="flex flex-col items-center gap-2">
                                <button @click="createPayments" :disabled="paymentsLoading"
                                    class="w-full rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 transition">
                                    <i class="fa-solid fa-credit-card mr-1.5"></i>
                                    {{ paymentsLoading ? 'Criando...' : 'Criar Pagamentos' }}
                                </button>
                                <p v-if="paymentsResult" class="text-sm font-medium text-gray-700">{{ paymentsResult }}
                                </p>
                            </div>
                        </template>

                        <!-- Player: payment card -->
                        <PixPaymentCard v-if="!is_admin && payment && isCurrentRound" :payment="payment" />
                    </template>

                    <!-- Admin: extra tools (current round only) -->
                    <template v-if="is_admin && isCurrentRound">
                        <div class="flex justify-center">
                            <button @click="sendWhatsAppTest" :disabled="whatsappTesting"
                                class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50 transition">
                                <i class="fa-brands fa-whatsapp mr-1.5"></i>
                                {{ whatsappTestLabel }}
                            </button>
                        </div>

                        <div class="flex flex-col items-center gap-2">
                            <button @click="regenerateAllWeekTeams" :disabled="allWeekTeamsLoading"
                                class="w-full rounded-md bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-purple-700 disabled:opacity-50 transition">
                                <i class="fa-solid fa-images mr-1.5"></i>
                                {{ allWeekTeamsLoading ? 'Gerando...' : 'Gerar Times da Semana (todas as rodadas)' }}
                            </button>
                            <p v-if="allWeekTeamsResult" class="text-sm font-medium text-gray-700">{{ allWeekTeamsResult
                                }}</p>
                        </div>

                        <FuturisticButton :label="captainsLoading ? 'Gerando...' : 'Gerar Capitães'"
                            @click="generateCaptains" />
                        <img v-if="captainsImage" :src="captainsImage" alt="Capitães"
                            class="w-full rounded-lg shadow" />

                        <FuturisticButton :label="lineupsLoading ? 'Gerando...' : 'Gerar Escalações'"
                            @click="generateLineups" />
                        <img v-if="lineupsImage" :src="lineupsImage" alt="Escalações"
                            class="w-full rounded-lg shadow" />
                    </template>
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
            <AddPlayerModal ref="playerModal" />
            <AddGuestModal ref="guestModal" :game-id="store.game?.id" />
        </template>
    </AppLayout>
</template>
