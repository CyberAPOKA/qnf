<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useGameStore } from '@/stores/gameStore';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    is_admin: Boolean,
});

const store = useGameStore();
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
    return props.is_admin || isMyTurn.value;
});

const pickUser = (userId) => {
    if (!store.game || !canPick.value) return;
    pickForm.user_id = userId;
    pickForm.post(route('games.pick', store.game.id), { preserveScroll: true, preserveState: false });
};

const roundText = computed(() => {
    const picksCount = store.game?.picks?.length || 0;
    return `Rodada ${Math.floor(picksCount / 3) + 1}`;
});

const pickText = computed(() => {
    const picksCount = store.game?.picks?.length || 0;
    return `Pick ${picksCount + 1}/12`;
});

const whatsappLink = computed(() => {
    if (!store.game?.whatsapp_message) return '#';
    return `https://wa.me/?text=${encodeURIComponent(store.game.whatsapp_message)}`;
});

const copyMessage = async () => {
    if (!store.game?.whatsapp_message) return;
    await navigator.clipboard.writeText(store.game.whatsapp_message);
};

const handleRealtimeEvent = (payload) => {
    store.patchFromEvent(payload);
};

onMounted(() => {
    store.hydrate(props.game);

    if (!store.channelName || !window.Echo) return;
    window.Echo.private(store.channelName)
        .listen('.CaptainsDrawn', handleRealtimeEvent)
        .listen('.DraftPickMade', handleRealtimeEvent)
        .listen('.DraftTurnChanged', handleRealtimeEvent)
        .listen('.DraftFinished', handleRealtimeEvent)
        .listen('.GamePlayerJoined', handleRealtimeEvent)
        .listen('.GameBecameFull', handleRealtimeEvent);
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
    <AppLayout title="Draft">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Draft dos Times</h2>
        </template>

        <div class="py-6 px-4">
            <div class="mx-auto max-w-6xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow">
                    <p class="text-sm text-gray-500">{{ roundText }} - {{ pickText }}</p>
                    <p class="mt-2 text-lg font-semibold text-gray-900" v-if="store.game?.status === 'drafting'">
                        {{ isMyTurn ? 'Sua vez' : `${turnCaptainName || 'Capitão'} escolhendo...` }}
                    </p>
                    <p v-else class="mt-2 text-lg font-semibold text-green-700">Draft finalizado</p>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-xl bg-green-50 p-4">
                        <p class="font-semibold text-green-800">Time Verde</p>
                        <p class="mt-1 text-sm text-green-700">
                            Capitão: {{ store.game?.teams?.green?.captain?.name || '-' }}
                        </p>
                        <ul class="mt-2 space-y-1 text-sm text-green-900">
                            <li v-for="player in store.game?.teams?.green?.players || []" :key="`g-${player.id}`">{{ player.name }}</li>
                        </ul>
                    </div>

                    <div class="rounded-xl bg-yellow-50 p-4">
                        <p class="font-semibold text-yellow-800">Time Amarelo</p>
                        <p class="mt-1 text-sm text-yellow-700">
                            Capitão: {{ store.game?.teams?.yellow?.captain?.name || '-' }}
                        </p>
                        <ul class="mt-2 space-y-1 text-sm text-yellow-900">
                            <li v-for="player in store.game?.teams?.yellow?.players || []" :key="`y-${player.id}`">{{ player.name }}</li>
                        </ul>
                    </div>

                    <div class="rounded-xl bg-blue-50 p-4">
                        <p class="font-semibold text-blue-800">Time Azul</p>
                        <p class="mt-1 text-sm text-blue-700">
                            Capitão: {{ store.game?.teams?.blue?.captain?.name || '-' }}
                        </p>
                        <ul class="mt-2 space-y-1 text-sm text-blue-900">
                            <li v-for="player in store.game?.teams?.blue?.players || []" :key="`b-${player.id}`">{{ player.name }}</li>
                        </ul>
                    </div>
                </div>

                <div v-if="store.game?.status === 'drafting'" class="rounded-xl bg-white p-4 shadow">
                    <h3 class="text-base font-semibold text-gray-900">Disponíveis</h3>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="player in store.game?.available_players || []"
                            :key="player.id"
                            class="flex items-center justify-between rounded-lg border border-gray-100 p-3"
                        >
                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ player.name }}</p>
                                <p class="text-xs text-gray-500">{{ player.position_label }}</p>
                            </div>
                            <PrimaryButton
                                class="px-4 py-2 text-sm"
                                :disabled="!canPick || pickForm.processing"
                                @click="pickUser(player.id)"
                            >
                                Escolher
                            </PrimaryButton>
                        </li>
                    </ul>
                </div>

                <div v-if="store.game?.status === 'done'" class="rounded-xl bg-white p-4 shadow space-y-3">
                    <h3 class="text-base font-semibold text-gray-900">Mensagem para WhatsApp</h3>
                    <textarea
                        :value="store.game?.whatsapp_message"
                        class="h-60 w-full rounded-lg border-gray-300 text-sm"
                        readonly
                    />
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <PrimaryButton class="w-full justify-center py-3" @click="copyMessage">
                            Copiar
                        </PrimaryButton>
                        <a
                            :href="whatsappLink"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center justify-center rounded-md bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-700"
                        >
                            Enviar no WhatsApp
                        </a>
                    </div>
                </div>

                <Link class="text-sm text-indigo-600" :href="route('dashboard')">Voltar ao dashboard</Link>
            </div>
        </div>
    </AppLayout>
</template>
