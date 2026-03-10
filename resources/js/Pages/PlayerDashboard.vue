<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import GameStatusCard from '@/Components/Game/GameStatusCard.vue';
import PlayerListCard from '@/Components/Game/PlayerListCard.vue';
import TeamCard from '@/Components/Game/TeamCard.vue';
import WhatsAppCard from '@/Components/Game/WhatsAppCard.vue';
import RankingCard from '@/Components/Game/RankingCard.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useGameChannel } from '@/composables/useGameChannel';
import { useDraftRedirect } from '@/composables/useDraftRedirect';
import { useCountdown } from '@/composables/useCountdown';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    is_goalkeeper: Boolean,
    dropped_out: Boolean,
    waitlist_position: Number,
    ranking: Array,
});

const { store } = useGameChannel(props);
useDraftRedirect();
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
    if (props.is_goalkeeper || props.dropped_out) return false;
    return store.game?.status === 'open' && !joined.value && linePlayerCount.value < 12;
});

const canJoinWaitlist = computed(() => {
    if (props.is_goalkeeper || props.dropped_out || props.waitlist_position) return false;
    return store.game?.status === 'drafted' && !joined.value;
});

const canQuit = computed(() => {
    if (props.waitlist_position) return false;
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
</script>

<template>
    <AppLayout title="">
        <template #header>
            <TitleCard />
        </template>

        <div class="p-2 lg:p-4">
            <div class="mx-auto max-w-xl space-y-4">
                <GameStatusCard :status="store.game?.status"
                    :status-label="store.game?.status_label" :players-count="store.game?.players_count"
                    :round="store.game?.round">
                    <template #actions>

                        <div v-if="store.game?.status === 'scheduled'" class="text-center">
                            <p class="font-bold text-xl text-gray-900">MERCADO EM</p>
                            <p v-if="countdown" class="text-3xl font-bold text-blue-900 tabular-nums">
                                {{ countdown }}
                            </p>
                        </div>

                        <template v-else-if="!is_goalkeeper">
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
                                <PrimaryButton v-if="canJoin" class="w-full justify-center py-3 text-base"
                                    :disabled="form.processing" @click="joinGame">
                                    Eu quero jogar
                                </PrimaryButton>

                                <PrimaryButton v-if="canJoinWaitlist"
                                    class="w-full justify-center py-3 text-base !bg-amber-500 hover:!bg-amber-600 focus:!bg-amber-600"
                                    :disabled="waitlistForm.processing" @click="joinWaitlist">
                                    Entrar na fila de espera
                                </PrimaryButton>

                                <button v-if="canQuit" @click="showQuitModal = true"
                                    class="w-full rounded-md border border-red-300 bg-white px-4 py-3 text-base font-semibold text-red-600 shadow-sm hover:bg-red-50">
                                    Eu quero desistir
                                </button>
                            </template>
                        </template>

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

                <PlayerListCard v-if="!['drafted', 'done'].includes(store.game?.status)" :players="store.game?.players || []" />

                <template v-if="['drafted', 'done'].includes(store.game?.status)">
                    <div class="grid grid-cols-1 gap-3">
                        <TeamCard color="green" :team="store.game?.teams?.green" />
                        <TeamCard color="yellow" :team="store.game?.teams?.yellow" />
                        <TeamCard color="blue" :team="store.game?.teams?.blue" />
                    </div>
                </template>

                <RankingCard :ranking="ranking || []" />
            </div>
        </div>

        <!-- Modal de confirmação de desistência -->
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
    </AppLayout>
</template>
