<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import GameStatusCard from '@/Components/Game/GameStatusCard.vue';
import PlayerListCard from '@/Components/Game/PlayerListCard.vue';
import TeamCard from '@/Components/Game/TeamCard.vue';
import AddGuestModal from '@/Components/Game/AddGuestModal.vue';
import AddPlayerModal from '@/Components/Game/AddPlayerModal.vue';
import ScoreEntryCard from '@/Components/Game/ScoreEntryCard.vue';
import RankingCard from '@/Components/Game/RankingCard.vue';
import WinsRankingCard from '@/Components/Game/WinsRankingCard.vue';
import PredictionCard from '@/Components/Game/PredictionCard.vue';
import PaymentManagementCard from '@/Components/Game/PaymentManagementCard.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';

import { useGameChannel } from '@/composables/useGameChannel';
import { useDraftRedirect } from '@/composables/useDraftRedirect';
import MultiSelect from 'primevue/multiselect';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    all_users: Array,
    can_enter_scores: Boolean,
    ranking: Array,
    wins_ranking: Array,
    payments: Array,
    prediction: Object,
});

const { store } = useGameChannel(props);
useDraftRedirect();
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
    return ['scheduled', 'open', 'full'].includes(store.game?.status);
});

const goalkeeperCount = computed(() => {
    return (store.game?.players || []).filter((p) => p.position === 'goalkeeper').length;
});

const linePlayerCount = computed(() => {
    return (store.game?.players || []).filter((p) => p.position !== 'goalkeeper').length;
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
    const teams = store.game?.teams;
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
    const msg = store.game?.whatsapp_message;
    if (!msg) return;
    try {
        await navigator.clipboard.writeText(msg);
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

</script>

<template>
    <AppLayout title="QNF">
        <template #header>
            <TitleCard />
        </template>

        <div class="p-2 lg:p-4">
            <div class="mx-auto max-w-xl space-y-4">
                <GameStatusCard :status="store.game?.status" :status-label="store.game?.status_label"
                    :players-count="store.game?.players_count" :round="store.game?.round">
                    <template #details>
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
                        <Link v-if="store.game?.status === 'drafting'"
                            class="inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-3 text-base font-semibold text-white hover:bg-indigo-700"
                            :href="route('games.draft', store.game.id)">
                            Ir para Draft
                        </Link>
                    </template>

                    <template #footer>
                        <p v-if="store.game?.status === 'full'" class="mt-3 text-sm font-medium text-red-600">
                            Lista fechada
                        </p>
                    </template>
                </GameStatusCard>

                <div v-if="canAddPlayers" class="rounded-xl bg-white p-2 lg:p-4 shadow">
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

                <div v-if="canAddPlayers" class="rounded-xl bg-white p-2 lg:p-4 shadow">
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

                <PlayerListCard v-if="!['drafted', 'done'].includes(store.game?.status)" :players="store.game?.players || []"
                    :game-id="store.game?.id" editable />

                <template v-if="['drafted', 'done'].includes(store.game?.status)">
                    <div class="grid grid-cols-1 gap-3">
                        <TeamCard color="green" :team="store.game?.teams?.green" editable :game-id="store.game?.id"
                            :available-players="availableForTeam" />
                        <TeamCard color="yellow" :team="store.game?.teams?.yellow" editable :game-id="store.game?.id"
                            :available-players="availableForTeam" />
                        <TeamCard color="blue" :team="store.game?.teams?.blue" editable :game-id="store.game?.id"
                            :available-players="availableForTeam" />
                    </div>
                    <button v-if="store.game?.whatsapp_message" @click="copyTeams"
                        class="w-full rounded-md border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 transition">
                        <i class="fa-regular fa-copy mr-1.5"></i>
                        {{ copyTeamsLabel }}
                    </button>
                    <ScoreEntryCard :game-id="store.game.id" :teams="store.game.teams" />
                    <PaymentManagementCard :payments="payments || []" />
                </template>

                <PredictionCard :prediction="prediction" />
                <RankingCard :ranking="ranking || []" />
                <WinsRankingCard :ranking="wins_ranking || []" />

                <div class="flex justify-center">
                    <button @click="sendWhatsAppTest" :disabled="whatsappTesting"
                        class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50 transition">
                        <i class="fa-brands fa-whatsapp mr-1.5"></i>
                        {{ whatsappTestLabel }}
                    </button>
                </div>
            </div>
        </div>

        <AddPlayerModal ref="playerModal" />
        <AddGuestModal ref="guestModal" :game-id="store.game?.id" />
    </AppLayout>
</template>