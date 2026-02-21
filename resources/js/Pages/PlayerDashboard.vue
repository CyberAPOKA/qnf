<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useGameStore } from '@/stores/gameStore';

const props = defineProps({
    game: Object,
    current_user_id: Number,
});

const store = useGameStore();
const form = useForm({});

const joined = computed(() => {
    return !!store.game?.players?.some((player) => player.id === props.current_user_id);
});

const canJoin = computed(() => {
    return store.game?.status === 'open' && !joined.value && store.game?.players_count < 15;
});

const joinGame = () => {
    if (!store.game) return;
    form.post(route('games.join', store.game.id), { preserveScroll: true, preserveState: false });
};

const handleRealtimeEvent = (payload) => {
    store.patchFromEvent(payload);
};

onMounted(() => {
    store.hydrate(props.game);
    if (!store.channelName || !window.Echo) return;

    window.Echo.private(store.channelName)
        .listen('.GamePlayerJoined', handleRealtimeEvent)
        .listen('.GameBecameFull', handleRealtimeEvent)
        .listen('.CaptainsDrawn', handleRealtimeEvent)
        .listen('.DraftPickMade', handleRealtimeEvent)
        .listen('.DraftTurnChanged', handleRealtimeEvent)
        .listen('.DraftFinished', handleRealtimeEvent);
});

watch(
    () => props.game,
    (game) => {
        store.hydrate(game);
    }
);

onBeforeUnmount(() => {
    if (store.channelName && window.Echo) {
        window.Echo.leave(`private-${store.channelName}`);
    }
});
</script>

<template>
    <AppLayout title="Jogo da Semana">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Futsal da Semana
            </h2>
        </template>

        <div class="py-6 px-4">
            <div class="mx-auto max-w-xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow">
                    <p class="text-sm text-gray-500">Status</p>
                    <p class="mt-1 text-lg font-semibold text-gray-900">{{ store.game?.status_label }}</p>
                    <p class="mt-2 text-sm text-gray-700">
                        Inscritos: <span class="font-semibold">{{ store.game?.players_count }}/15</span>
                    </p>

                    <div class="mt-4 space-y-2">
                        <PrimaryButton
                            class="w-full justify-center py-3 text-base"
                            :disabled="form.processing || !canJoin"
                            @click="joinGame"
                        >
                            Eu quero jogar
                        </PrimaryButton>

                        <Link
                            v-if="['drafting', 'done'].includes(store.game?.status)"
                            class="inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-3 text-base font-semibold text-white hover:bg-indigo-700"
                            :href="route('games.draft', store.game.id)"
                        >
                            Ir para Draft
                        </Link>
                    </div>

                    <p v-if="store.game?.status === 'full'" class="mt-3 text-sm font-medium text-red-600">
                        Lista fechada
                    </p>
                </div>

                <div class="rounded-xl bg-white p-4 shadow">
                    <h3 class="text-base font-semibold text-gray-900">Inscritos</h3>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="player in store.game?.players || []"
                            :key="player.id"
                            class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2"
                        >
                            <span class="text-sm font-medium text-gray-900">{{ player.name }}</span>
                            <span
                                class="rounded-full px-2 py-1 text-xs font-semibold bg-gray-100 text-gray-700"
                            >
                                {{ player.position_label }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
