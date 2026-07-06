<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import DataTable from '@/Components/DataTable.vue';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';
import PlayerFormModal from '@/Components/Game/PlayerFormModal.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    players: Array,
    done_games: Array,
});

const positionLabels = {
    goalkeeper: 'Goleiro',
    fixed: 'Fixo',
    winger: 'Ala',
    pivot: 'Pivô',
};

const columns = [
    { key: 'name', label: 'Jogador' },
    { key: 'phone', label: 'Telefone' },
    { key: 'position', label: 'Posição', align: 'center' },
    { key: 'ability', label: 'Hab.', align: 'center' },
    { key: 'active', label: 'Ativo', align: 'center' },
    { key: 'actions', label: 'Ações', align: 'center' },
];

const guests = computed(() => props.players.filter(p => p.guest));

const search = ref('');
const filteredPlayers = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.players;
    return props.players.filter(p => p.name?.toLowerCase().includes(term));
});

const rowClass = (row) => (row.active ? '' : 'opacity-50');

const playerFormModal = ref(null);

const openCreate = () => {
    playerFormModal.value?.openCreate();
};

const onSelectGuest = (event) => {
    const guestId = Number(event.target.value);
    event.target.value = '';

    if (! guestId) {
        return;
    }

    const guest = guests.value.find((g) => g.id === guestId);

    if (guest) {
        playerFormModal.value?.openConvert(guest);
    }
};

const openEdit = (player) => {
    playerFormModal.value?.openEdit(player);
};

// --- Suspension modal ---
const showSuspendModal = ref(false);
const suspendingPlayer = ref(null);

const suspendForm = useForm({
    round: '',
    duration: '',
});

const suspensionLabel = (player) => {
    if (player.suspended_until_round === null) return null;
    if (player.suspended_until_round === 0) return 'Permanente';
    return `Até rodada ${player.suspended_until_round}`;
};

const openSuspend = (player) => {
    suspendingPlayer.value = player;
    suspendForm.reset();
    suspendForm.clearErrors();
    showSuspendModal.value = true;
};

const closeSuspend = () => {
    showSuspendModal.value = false;
    suspendingPlayer.value = null;
    suspendForm.reset();
    suspendForm.clearErrors();
};

const submitSuspend = () => {
    suspendForm.post(route('admin.players.suspend', suspendingPlayer.value.id), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => closeSuspend(),
    });
};

const unsuspend = () => {
    suspendForm.post(route('admin.players.unsuspend', suspendingPlayer.value.id), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => closeSuspend(),
    });
};
</script>

<template>
    <AppLayout title="Jogadores">
        <template #header>
            <TitleCard />
        </template>

        <div class="p-1 lg:p-4">
            <div class="mx-auto max-w-4xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-base font-semibold text-gray-900">Jogadores</h3>
                        <div class="flex flex-1 items-center justify-end gap-2">
                            <TextInput v-model="search" type="search" placeholder="Buscar por nome..."
                                class="w-full max-w-xs text-sm" />
                            <select v-if="guests.length" @change="onSelectGuest"
                                class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Converter convidado...</option>
                                <option v-for="g in guests" :key="g.id" :value="g.id">
                                    {{ g.name }}
                                </option>
                            </select>
                            <button @click="openCreate"
                                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                                Criar jogador
                            </button>
                        </div>
                    </div>

                    <DataTable :columns="columns" :rows="filteredPlayers" numbered :row-class="rowClass"
                        empty-message="Nenhum jogador encontrado.">
                        <template #cell-name="{ row }">
                            <div class="flex items-center gap-3">
                                <PlayerPhoto :src="row.photo_front" :initial="row.name.charAt(0)" :alt="row.name" size="md" />
                                <span class="font-medium text-gray-900">{{ row.name }}</span>
                                <span v-if="row.guest" class="rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-700">Convidado</span>
                            </div>
                        </template>
                        <template #cell-position="{ row }">
                            <PositionBadge :position="row.position" :label="positionLabels[row.position] || row.position" />
                        </template>
                        <template #cell-ability="{ row }">
                            <span class="font-bold" :class="{
                                'text-red-600': row.ability <= 3,
                                'text-yellow-600': row.ability >= 4 && row.ability <= 6,
                                'text-green-600': row.ability >= 7,
                            }">{{ row.ability ?? 5 }}</span>
                        </template>
                        <template #cell-active="{ row }">
                            <span :class="[
                                'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                row.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700',
                            ]">
                                {{ row.active ? 'Sim' : 'Não' }}
                            </span>
                        </template>
                        <template #cell-actions="{ row }">
                            <div class="flex items-center justify-center gap-3">
                                <button @click="openEdit(row)" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                                    Editar
                                </button>
                                <button @click="openSuspend(row)" class="text-sm font-medium text-red-600 hover:text-red-500">
                                    Suspender
                                </button>
                            </div>
                            <span v-if="suspensionLabel(row)" class="mt-1 inline-block rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">
                                {{ suspensionLabel(row) }}
                            </span>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <PlayerFormModal ref="playerFormModal" />

        <!-- Modal Suspensão -->
        <DialogModal :show="showSuspendModal" @close="closeSuspend">
            <template #title>Suspender jogador</template>

            <template #content>
                <div v-if="suspendingPlayer" class="space-y-4">
                    <p class="text-sm text-gray-700">
                        Jogador: <span class="font-semibold">{{ suspendingPlayer.name }}</span>
                    </p>

                    <div v-if="suspendingPlayer.suspended_until_round !== null"
                        class="rounded-lg border border-red-200 bg-red-50 p-3">
                        <p class="text-sm font-semibold text-red-700">
                            Este jogador já está suspenso
                            <span v-if="suspendingPlayer.suspended_until_round === 0">(permanente)</span>
                            <span v-else>(até a rodada {{ suspendingPlayer.suspended_until_round }})</span>
                        </p>
                    </div>

                    <div>
                        <InputLabel value="Rodada da infração" />
                        <select v-model="suspendForm.round"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="" disabled>Selecione a rodada</option>
                            <option v-for="game in done_games" :key="game.id" :value="game.round">
                                Rodada {{ game.round }}
                            </option>
                        </select>
                        <InputError :message="suspendForm.errors.round" class="mt-2" />
                    </div>

                    <div>
                        <InputLabel value="Duração da suspensão" />
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <button v-for="opt in [
                                { value: '1', label: '1 semana' },
                                { value: '2', label: '2 semanas' },
                                { value: '3', label: '3 semanas' },
                                { value: 'permanent', label: 'Permanente' },
                            ]" :key="opt.value" type="button" @click="suspendForm.duration = opt.value"
                                :class="[
                                    'rounded-lg border px-4 py-2 text-sm font-medium transition',
                                    suspendForm.duration === opt.value
                                        ? 'border-red-600 bg-red-600 text-white'
                                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
                                ]">
                                {{ opt.label }}
                            </button>
                        </div>
                        <InputError :message="suspendForm.errors.duration" class="mt-2" />
                    </div>

                    <div v-if="suspendForm.round && suspendForm.duration" class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600">
                        <template v-if="suspendForm.duration === 'permanent'">
                            O jogador será suspenso <span class="font-semibold text-red-600">permanentemente</span>.
                        </template>
                        <template v-else>
                            O jogador não poderá jogar da rodada
                            <span class="font-semibold">{{ Number(suspendForm.round) + 1 }}</span>
                            até a rodada
                            <span class="font-semibold">{{ Number(suspendForm.round) + Number(suspendForm.duration) }}</span>.
                            Poderá voltar na
                            <span class="font-semibold text-green-600">rodada {{ Number(suspendForm.round) + Number(suspendForm.duration) + 1 }}</span>.
                        </template>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="flex w-full items-center justify-between">
                    <button v-if="suspendingPlayer?.suspended_until_round !== null && suspendingPlayer?.suspended_until_round !== undefined"
                        type="button" @click="unsuspend"
                        class="rounded-lg border border-green-600 px-4 py-2 text-sm font-semibold text-green-600 hover:bg-green-50">
                        Remover punição
                    </button>
                    <span v-else />

                    <div class="flex gap-2">
                        <SecondaryButton @click="closeSuspend">Cancelar</SecondaryButton>
                        <button type="button" @click="submitSuspend"
                            :disabled="suspendForm.processing || !suspendForm.round || !suspendForm.duration"
                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50">
                            Suspender
                        </button>
                    </div>
                </div>
            </template>
        </DialogModal>
    </AppLayout>
</template>
