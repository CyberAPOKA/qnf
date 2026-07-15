<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import DataTable from '@/Components/DataTable.vue';
import PlayerPhoto from '@/Components/Game/PlayerPhoto.vue';
import PlayerFormModal from '@/Components/Game/PlayerFormModal.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    players: Array,
    done_games: Array,
    current_round: Number,
});

const columns = [
    { key: 'name', label: 'Jogador', align: 'center', class: 'w-32 max-w-32' },
    { key: 'cards', label: 'Cartões', align: 'center', sortable: true, class: 'w-28' },
    { key: 'rounds_played', label: 'Rodadas', align: 'center', sortable: true, class: 'w-20' },
    { key: 'actions', label: 'Ações', align: 'center' },
];

const guests = computed(() => props.players.filter(p => p.guest));

const search = ref('');
const sortKey = ref(null);
const sortDir = ref('asc');

const filteredPlayers = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.players;
    return props.players.filter(p => p.name?.toLowerCase().includes(term));
});

const sortedPlayers = computed(() => {
    const list = [...filteredPlayers.value];

    if (!sortKey.value) {
        return list;
    }

    list.sort((a, b) => {
        const left = sortKey.value === 'cards' ? a.cards_count : a.rounds_played;
        const right = sortKey.value === 'cards' ? b.cards_count : b.rounds_played;

        if (left !== right) {
            return sortDir.value === 'asc' ? left - right : right - left;
        }

        return a.name.localeCompare(b.name, 'pt-BR');
    });

    return list;
});

const onSort = (key) => {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
};

const rowClass = (row) => (row.active ? '' : 'opacity-50');

const firstName = (name) => name?.trim().split(/\s+/)[0] ?? '';

const playerFormModal = ref(null);

const openCreate = () => {
    playerFormModal.value?.openCreate();
};

const onSelectGuest = (event) => {
    const guestId = Number(event.target.value);
    event.target.value = '';

    if (!guestId) return;

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

// --- Cards modal ---
const showCardsModal = ref(false);
const cardsPlayer = ref(null);

const cardForm = useForm({
    type: '',
    round: '',
});

const defaultCardRound = computed(() => props.done_games?.[0]?.round ?? props.current_round ?? '');

const openCards = (player) => {
    cardsPlayer.value = player;
    cardForm.reset();
    cardForm.clearErrors();
    cardForm.round = defaultCardRound.value;
    showCardsModal.value = true;
};

const closeCards = () => {
    showCardsModal.value = false;
    cardsPlayer.value = null;
    cardForm.reset();
    cardForm.clearErrors();
};

const submitCard = (type) => {
    cardForm.type = type;
    cardForm.post(route('admin.players.cards.store', cardsPlayer.value.id), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => closeCards(),
    });
};

// --- Delete player ---
const showDeleteModal = ref(false);
const deletingPlayer = ref(null);
const playerToDelete = ref(null);

const openDelete = (player) => {
    playerToDelete.value = player;
    showDeleteModal.value = true;
};

const closeDelete = () => {
    showDeleteModal.value = false;
    playerToDelete.value = null;
};

const submitDelete = () => {
    if (!playerToDelete.value) return;

    deletingPlayer.value = playerToDelete.value.id;
    router.delete(route('admin.players.destroy', playerToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => closeDelete(),
        onFinish: () => {
            deletingPlayer.value = null;
        },
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

                    <DataTable
                        :columns="columns"
                        :rows="sortedPlayers"
                        :row-class="rowClass"
                        :sort-key="sortKey"
                        :sort-dir="sortDir"
                        empty-message="Nenhum jogador encontrado."
                        @sort="onSort"
                    >
                        <template #cell-name="{ row }">
                            <div class="flex justify-center">
                                <div class="relative flex h-28 w-28 items-center justify-center overflow-hidden">
                                    <PlayerPhoto
                                        :src="row.photo_front"
                                        :initial="row.name.charAt(0)"
                                        :alt="row.name"
                                        size="md"
                                    />
                                    <div
                                        class="absolute inset-x-0 bottom-0 rounded bg-yellow-400 px-1 py-0.5 text-center text-xs font-bold text-black truncate"
                                    >
                                        {{ firstName(row.name) }}
                                        <i v-if="row.guest" class="fa-solid fa-c text-red-500"></i>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template #cell-cards="{ row }">
                            <div class="flex items-center justify-center gap-1">
                                <template v-if="row.display_cards?.length">
                                    <span
                                        v-for="(card, index) in row.display_cards"
                                        :key="`${card.type}-${card.round}-${index}`"
                                        class="inline-flex h-6 w-4 rounded-sm shadow-sm"
                                        :class="card.type === 'yellow' ? 'bg-yellow-400' : 'bg-red-600'"
                                        :title="`Rodada ${card.round}`"
                                    ></span>
                                </template>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </div>
                        </template>

                        <template #cell-rounds_played="{ row }">
                            <span class="font-bold text-gray-900">{{ row.rounds_played }}</span>
                        </template>

                        <template #cell-actions="{ row }">
                            <div class="flex flex-wrap items-center justify-center gap-2">
                                <button @click="openEdit(row)"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                                    Editar
                                </button>
                                <button @click="openCards(row)"
                                    class="text-sm font-medium text-amber-600 hover:text-amber-500">
                                    Cartões
                                </button>
                                <button @click="openSuspend(row)"
                                    class="text-sm font-medium text-red-600 hover:text-red-500">
                                    Suspender
                                </button>
                                <button
                                    @click="openDelete(row)"
                                    :disabled="deletingPlayer === row.id"
                                    class="text-sm font-medium text-gray-500 hover:text-gray-700 disabled:opacity-50"
                                >
                                    Excluir
                                </button>
                            </div>
                            <span v-if="suspensionLabel(row)"
                                class="mt-1 inline-block rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">
                                {{ suspensionLabel(row) }}
                            </span>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <PlayerFormModal ref="playerFormModal" />

        <!-- Modal Excluir -->
        <DialogModal :show="showDeleteModal" @close="closeDelete">
            <template #title>Excluir jogador</template>

            <template #content>
                <div v-if="playerToDelete" class="space-y-4">
                    <div class="flex flex-col items-center gap-3">
                        <div class="relative flex h-24 w-24 items-center justify-center overflow-hidden rounded-lg bg-gray-100">
                            <PlayerPhoto
                                :src="playerToDelete.photo_front"
                                :initial="playerToDelete.name.charAt(0)"
                                :alt="playerToDelete.name"
                                size="md"
                            />
                        </div>
                        <p class="text-base font-semibold text-gray-900">{{ playerToDelete.name }}</p>
                    </div>

                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        <p class="font-semibold">Tem certeza que deseja excluir este jogador?</p>
                        <p class="mt-2">
                            O jogador será removido da lista, junto com inscrições, pagamentos, cartões e demais vínculos.
                            Os dados não são apagados permanentemente do sistema.
                        </p>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="flex gap-2">
                    <SecondaryButton @click="closeDelete">Cancelar</SecondaryButton>
                    <button
                        type="button"
                        @click="submitDelete"
                        :disabled="deletingPlayer !== null"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
                    >
                        {{ deletingPlayer !== null ? 'Excluindo...' : 'Excluir jogador' }}
                    </button>
                </div>
            </template>
        </DialogModal>

        <!-- Modal Cartões -->
        <DialogModal :show="showCardsModal" @close="closeCards">
            <template #title>Cartões</template>

            <template #content>
                <div v-if="cardsPlayer" class="space-y-4">
                    <div class="flex flex-col items-center gap-3">
                        <div class="relative flex h-28 w-28 items-center justify-center overflow-hidden">
                            <PlayerPhoto
                                :src="cardsPlayer.photo_front"
                                :initial="cardsPlayer.name.charAt(0)"
                                :alt="cardsPlayer.name"
                                size="md"
                            />
                        </div>
                        <p class="text-base font-semibold text-gray-900">{{ cardsPlayer.name }}</p>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-gray-700">Cartões ativos</p>
                        <div class="flex flex-wrap items-center justify-center gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3 min-h-[3rem]">
                            <template v-if="cardsPlayer.display_cards?.length">
                                <span
                                    v-for="(card, index) in cardsPlayer.display_cards"
                                    :key="`modal-${card.type}-${card.round}-${index}`"
                                    class="inline-flex h-8 w-5 rounded-sm shadow"
                                    :class="card.type === 'yellow' ? 'bg-yellow-400' : 'bg-red-600'"
                                    :title="`Rodada ${card.round}`"
                                ></span>
                            </template>
                            <span v-else class="text-sm text-gray-500">Nenhum cartão ativo</span>
                        </div>
                    </div>

                    <div v-if="cardsPlayer.card_history?.length">
                        <p class="mb-2 text-sm font-semibold text-gray-700">Histórico</p>
                        <ul class="max-h-32 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2 text-sm text-gray-600">
                            <li v-for="card in cardsPlayer.card_history" :key="card.id">
                                <span
                                    class="mr-2 inline-block h-4 w-3 rounded-sm align-middle"
                                    :class="card.type === 'yellow' ? 'bg-yellow-400' : 'bg-red-600'"
                                ></span>
                                Cartão {{ card.type === 'yellow' ? 'amarelo' : 'vermelho' }} — Rodada {{ card.round }}
                            </li>
                        </ul>
                    </div>

                    <div>
                        <InputLabel value="Rodada do cartão" />
                        <select v-model="cardForm.round"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="" disabled>Selecione a rodada</option>
                            <option v-for="game in done_games" :key="game.id" :value="game.round">
                                Rodada {{ game.round }}
                            </option>
                        </select>
                        <InputError :message="cardForm.errors.round" class="mt-2" />
                        <InputError :message="cardForm.errors.type" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            @click="submitCard('yellow')"
                            :disabled="cardForm.processing || !cardForm.round"
                            class="rounded-lg border border-yellow-500 bg-yellow-400 px-4 py-2 text-sm font-semibold text-black shadow-sm hover:bg-yellow-300 disabled:opacity-50"
                        >
                            + Amarelo
                        </button>
                        <button
                            type="button"
                            @click="submitCard('red')"
                            :disabled="cardForm.processing || !cardForm.round"
                            class="rounded-lg border border-red-700 bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
                        >
                            + Vermelho
                        </button>
                    </div>

                    <p class="text-xs text-gray-500">
                        3 amarelos ou 1 vermelho aplicam punição de 1 rodada. Os cartões permanecem visíveis até o jogador poder jogar novamente.
                    </p>
                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="closeCards">Fechar</SecondaryButton>
            </template>
        </DialogModal>

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
                            ]" :key="opt.value" type="button" @click="suspendForm.duration = opt.value" :class="[
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

                    <div v-if="suspendForm.round && suspendForm.duration"
                        class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600">
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
                    <button
                        v-if="suspendingPlayer?.suspended_until_round !== null && suspendingPlayer?.suspended_until_round !== undefined"
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
