<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import GameStatusCard from '@/Components/Game/GameStatusCard.vue';
import PlayerListCard from '@/Components/Game/PlayerListCard.vue';
import TeamCard from '@/Components/Game/TeamCard.vue';
import WhatsAppCard from '@/Components/Game/WhatsAppCard.vue';
import AddGuestModal from '@/Components/Game/AddGuestModal.vue';
import AddPlayerModal from '@/Components/Game/AddPlayerModal.vue';
import ScoreEntryCard from '@/Components/Game/ScoreEntryCard.vue';
import RankingCard from '@/Components/Game/RankingCard.vue';
import { Link, useForm } from '@inertiajs/vue3';

import { useGameChannel } from '@/composables/useGameChannel';
import { useDraftRedirect } from '@/composables/useDraftRedirect';
import MultiSelect from 'primevue/multiselect';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    all_users: Array,
    can_enter_scores: Boolean,
    ranking: Array,
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

</script>

<template>
    <AppLayout title="QNF">
        <template #header>
            <h2 class="font-semibold text-lg text-gray-800 leading-tight text-center">
                <i class="fa-solid fa-fire"></i> QUINTA NOBRE FUTSAL 2026 <i class="fa-solid fa-fire"></i>
            </h2>
        </template>

        <div class="p-2 lg:p-4">
            <div class="mx-auto max-w-xl space-y-4">
                <GameStatusCard :status-label="store.game?.status_label" :players-count="store.game?.players_count"
                    :round="store.game?.round">
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

                <PlayerListCard :players="store.game?.players || []" />

                <template v-if="store.game?.status === 'done'">
                    <div class="grid grid-cols-1 gap-3">
                        <TeamCard color="green" :team="store.game?.teams?.green" />
                        <TeamCard color="yellow" :team="store.game?.teams?.yellow" />
                        <TeamCard color="blue" :team="store.game?.teams?.blue" />
                    </div>
                    <ScoreEntryCard :game-id="store.game.id" :teams="store.game.teams" />
                    <WhatsAppCard :message="store.game?.whatsapp_message || ''" />
                </template>

                <RankingCard :ranking="ranking || []" />
            </div>
        </div>

        <AddPlayerModal ref="playerModal" />
        <AddGuestModal ref="guestModal" :game-id="store.game?.id" />
    </AppLayout>
</template>
