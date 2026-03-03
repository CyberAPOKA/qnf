<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
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
    ranking: Array,
});

const { store } = useGameChannel(props);
useDraftRedirect();
const form = useForm({});

const joined = computed(() => {
    return !!store.game?.players?.some((player) => player.id === props.current_user_id);
});

const linePlayerCount = computed(() => {
    return (store.game?.players || []).filter((p) => p.position !== 'goalkeeper').length;
});

const canJoin = computed(() => {
    if (props.is_goalkeeper) return false;
    return store.game?.status === 'open' && !joined.value && linePlayerCount.value < 12;
});

const joinGame = () => {
    if (!store.game) return;
    form.post(route('games.join', store.game.id), { preserveScroll: true, preserveState: false });
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
                <GameStatusCard v-if="store.game?.status !== 'done'" :status="store.game?.status" :status-label="store.game?.status_label"
                    :players-count="store.game?.players_count" :round="store.game?.round">
                    <template #actions>

                        <div v-if="store.game?.status === 'scheduled'" class="text-center">
                            <p class="font-bold text-xl text-gray-900">MERCADO EM</p>
                            <p v-if="countdown" class="text-3xl font-bold text-blue-900 tabular-nums">
                                {{ countdown }}
                            </p>
                        </div>

                        <PrimaryButton v-else v-if="!is_goalkeeper" class="w-full justify-center py-3 text-base"
                            :disabled="form.processing || !canJoin" @click="joinGame">
                            Eu quero jogar
                        </PrimaryButton>

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

                <PlayerListCard v-if="store.game?.status !== 'done'" :players="store.game?.players || []" />

                <template v-if="store.game?.status === 'done'">
                    <div class="grid grid-cols-1 gap-3">
                        <TeamCard color="green" :team="store.game?.teams?.green" />
                        <TeamCard color="yellow" :team="store.game?.teams?.yellow" />
                        <TeamCard color="blue" :team="store.game?.teams?.blue" />
                    </div>
                    <WhatsAppCard :message="store.game?.whatsapp_message || ''" />
                </template>

                <RankingCard :ranking="ranking || []" />
            </div>
        </div>
    </AppLayout>
</template>