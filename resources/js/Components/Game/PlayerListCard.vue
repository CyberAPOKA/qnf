<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';
import EletricCard from '@/Components/EletricCard.vue';

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
        <h3 class="text-2xl font-bold text-gray-900 text-center">Inscritos</h3>

        <div class="grid grid-cols-2 lg:grid-cols-3 gap-2">
            <EletricCard v-for="player in players" :key="player.id" class="flex flex-col items-center gap-2">
                <template #default>
                    <div class="flex flex-col items-center justify-center">
                        <PlayerPhoto :src="player.photo_front || '/assets/week_team/unknown_player.png'"
                            :initial="player.initial" :alt="player.name" size="md" />
                        <div class="border border-b border-orange-400 mt-2 w-full"></div>
                        <span class="font-bold text-white lg:text-lg">
                            {{ player.name }}
                        </span>
                        <Button v-if="editable" severity="danger" size="small" @click="askRemove(player)"
                            class="!w-fit !absolute !top-2 !right-2">
                            <i class="fa-solid fa-xmark"></i>
                        </Button>
                    </div>
                </template>
            </EletricCard>
        </div>

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
