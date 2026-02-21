<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useGameStore } from '@/stores/gameStore';
import MultiSelect from 'primevue/multiselect';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    all_users: Array,
});

const store = useGameStore();
const form = useForm({});

const selectedUsers = ref([]);
const addPlayersForm = useForm({ user_ids: [] });

const availableUsers = computed(() => {
    const joinedIds = (store.game?.players || []).map((p) => p.id);
    return (props.all_users || []).filter((u) => !joinedIds.includes(u.id));
});

const canAddPlayers = computed(() => {
    return ['scheduled', 'open', 'full'].includes(store.game?.status);
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

const drawCaptains = () => {
    if (!store.game) return;
    form.post(route('games.draw-captains', store.game.id), { preserveScroll: true, preserveState: false });
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
    <AppLayout title="Admin - Jogo da Semana">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Futsal da Semana (Admin)
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
                            v-if="store.game?.status === 'full'"
                            class="w-full justify-center py-3 text-base bg-amber-500 hover:bg-amber-600 focus:bg-amber-600"
                            :disabled="form.processing"
                            @click="drawCaptains"
                        >
                            Sortear capitães
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

                <div v-if="canAddPlayers" class="rounded-xl bg-white p-4 shadow">
                    <h3 class="text-base font-semibold text-gray-900">Adicionar jogadores</h3>
                    <div class="mt-3 space-y-3">
                        <MultiSelect
                            v-model="selectedUsers"
                            :options="availableUsers"
                            optionLabel="name"
                            placeholder="Selecione jogadores"
                            filter
                            :maxSelectedLabels="3"
                            class="w-full"
                        >
                            <template #option="{ option }">
                                <div class="flex items-center gap-2">
                                    <span>{{ option.name }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700">
                                        {{ option.position_label }}
                                    </span>
                                </div>
                            </template>
                        </MultiSelect>
                        <PrimaryButton
                            class="w-full justify-center py-3 text-base"
                            :disabled="addPlayersForm.processing || !selectedUsers.length"
                            @click="addPlayers"
                        >
                            Adicionar selecionados
                        </PrimaryButton>
                    </div>
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
                            <span class="rounded-full px-2 py-1 text-xs font-semibold bg-gray-100 text-gray-700">
                                {{ player.position_label }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
