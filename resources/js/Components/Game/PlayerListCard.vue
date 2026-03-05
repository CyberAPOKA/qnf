<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import PositionBadge from '@/Components/Game/PositionBadge.vue';

const props = defineProps({
    players: {
        type: Array,
        default: () => [],
    },
    gameId: {
        type: Number,
        default: null,
    },
    editable: {
        type: Boolean,
        default: false,
    },
});

const removeForm = useForm({ user_id: null });
const confirmVisible = ref(false);
const playerToRemove = ref(null);

const askRemove = (player) => {
    playerToRemove.value = player;
    confirmVisible.value = true;
};

const confirmRemove = () => {
    if (!props.gameId || !playerToRemove.value) return;
    removeForm.user_id = playerToRemove.value.id;
    removeForm.post(route('games.remove-player', props.gameId), {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => {
            confirmVisible.value = false;
            playerToRemove.value = null;
        },
    });
};
</script>

<template>
    <div class="rounded-xl bg-white p-2 lg:p-4 shadow" v-if="players.length">
        <h3 class="text-base font-semibold text-gray-900">Inscritos</h3>
        <ul class="mt-3 space-y-2">
            <li v-for="player in players" :key="player.id"
                class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-gray-900">{{ player.name }}</span>
                    <PositionBadge :position="player.position" :label="player.position_label" />
                    <span v-if="player.guest"
                        class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-700">
                        Convidado
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <Button v-if="editable" severity="danger" size="small"
                        @click="askRemove(player)">
                        Remover <i class="fa-solid fa-xmark"></i>
                    </Button>
                </div>
            </li>
        </ul>

        <Dialog v-model:visible="confirmVisible" modal header="Remover jogador" :style="{ width: '20rem' }">
            <p class="text-sm text-gray-700">
                Remover <span class="font-semibold">{{ playerToRemove?.name }}</span> da lista?
            </p>
            <p class="mt-1 text-xs text-gray-500">O jogador não poderá se inscrever novamente.</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button label="Cancelar" severity="secondary" size="small" @click="confirmVisible = false" />
                    <Button label="Remover" severity="danger" size="small" :loading="removeForm.processing"
                        @click="confirmRemove" />
                </div>
            </template>
        </Dialog>
    </div>
</template>
