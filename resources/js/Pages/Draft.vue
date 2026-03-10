<script setup>
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DraftStatusCard from '@/Components/Game/DraftStatusCard.vue';
import TeamCard from '@/Components/Game/TeamCard.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';

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
    if (teamHasGoalkeeper.value && player.position === 'goalkeeper') return false;
    if (teamLinePickCount.value >= 3 && player.position !== 'goalkeeper') return false;
    return true;
};

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

        <div class="p-2 lg:p-4">
            <div class="mx-auto max-w-6xl space-y-4">
                <DraftStatusCard :round-text="roundText" :pick-text="pickText" :status="store.game?.status || ''"
                    :is-my-turn="isMyTurn" :turn-captain-name="turnCaptainName" />

                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <TeamCard color="green" :team="store.game?.teams?.green" />
                    <TeamCard color="yellow" :team="store.game?.teams?.yellow" />
                    <TeamCard color="blue" :team="store.game?.teams?.blue" />
                </div>

                <div v-if="store.game?.status === 'drafting'" class="rounded-xl bg-white p-2 lg:p-4 shadow">
                    <h3 class="text-lg font-bold text-red-500 text-center">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Clique e confirme para escolher
                        <i class="fa-solid fa-triangle-exclamation"></i>
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
                    <ul class="mt-3 space-y-2">
                        <li v-for="player in store.game?.available_players || []" :key="player.id"
                            class="flex flex-col gap-2 rounded-lg border border-gray-100 p-2">

                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-gray-900">{{ player.name }}</p>
                                <PrimaryButton v-if="canPickPlayer(player)" class="px-4 py-2 text-sm"
                                    :disabled="pickForm.processing" @click="confirmPick(player)">
                                    Escolher
                                </PrimaryButton>
                            </div>
                            <div class="flex items-center gap-4">
                                <PositionBadge :position="player.position" :label="player.position_label" />
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
                        </li>
                    </ul>
                </div>

            </div>
        </div>
        <ConfirmationModal :show="playerToConfirm !== null" @close="cancelPick">
            <template #title>Confirmar escolha</template>
            <template #content>
                <p class="text-gray-900">Deseja escolher <strong class="text-base">{{ playerToConfirm?.name }}</strong>?</p>
            </template>
            <template #footer>
                <SecondaryButton @click="cancelPick">Cancelar</SecondaryButton>
                <PrimaryButton class="ms-3" :disabled="pickForm.processing" @click="pickUser">
                    Confirmar
                </PrimaryButton>
            </template>
        </ConfirmationModal>
    </AppLayout>
</template>
