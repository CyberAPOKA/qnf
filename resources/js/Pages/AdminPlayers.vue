<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import DataTable from '@/Components/DataTable.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    players: Array,
    done_games: Array,
});

const positions = [
    { value: 'goalkeeper', label: 'Goleiro' },
    { value: 'fixed', label: 'Fixo' },
    { value: 'winger', label: 'Ala' },
    { value: 'pivot', label: 'Pivô' },
];

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

const rowClass = (row) => (row.active ? '' : 'opacity-50');

// --- Modal state ---
const showModal = ref(false);
const editingPlayer = ref(null);
const convertingGuest = ref(null);
const isEditing = computed(() => editingPlayer.value !== null);
const isConverting = computed(() => convertingGuest.value !== null);

const form = useForm({
    name: '',
    phone: '',
    position: 'winger',
    ability: 5,
    password: '',
    active: true,
    photo_front: null,
    photo_side: null,
});

const photoFrontPreview = ref(null);
const photoSidePreview = ref(null);

const openCreate = () => {
    editingPlayer.value = null;
    convertingGuest.value = null;
    form.reset();
    form.clearErrors();
    photoFrontPreview.value = null;
    photoSidePreview.value = null;
    showModal.value = true;
};

const onSelectGuest = (event) => {
    const guestId = Number(event.target.value);
    event.target.value = '';
    if (!guestId) return;
    const guest = guests.value.find(g => g.id === guestId);
    if (!guest) return;

    editingPlayer.value = null;
    convertingGuest.value = guest;
    form.reset();
    form.clearErrors();
    form.name = guest.name;
    form.position = guest.position;
    form.phone = '';
    form.password = '';
    form.active = true;
    photoFrontPreview.value = guest.photo_front;
    photoSidePreview.value = guest.photo_side;
    showModal.value = true;
};

const openEdit = (player) => {
    editingPlayer.value = player;
    form.clearErrors();
    form.name = player.name;
    form.phone = player.phone;
    form.position = player.position;
    form.ability = player.ability ?? 5;
    form.password = '';
    form.active = player.active;
    form.photo_front = null;
    form.photo_side = null;
    photoFrontPreview.value = player.photo_front;
    photoSidePreview.value = player.photo_side;
    showModal.value = true;
};

const close = () => {
    showModal.value = false;
    editingPlayer.value = null;
    convertingGuest.value = null;
    form.reset();
    form.clearErrors();
    photoFrontPreview.value = null;
    photoSidePreview.value = null;
};

const onFileChange = (field, event) => {
    const file = event.target.files[0];
    if (!file) return;
    form[field] = file;

    const reader = new FileReader();
    reader.onload = (e) => {
        if (field === 'photo_front') photoFrontPreview.value = e.target.result;
        else photoSidePreview.value = e.target.result;
    };
    reader.readAsDataURL(file);
};

const submit = () => {
    if (isConverting.value) {
        form.post(route('admin.players.convert-guest', convertingGuest.value.id), {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => close(),
        });
    } else if (isEditing.value) {
        form.post(route('admin.players.update', editingPlayer.value.id), {
            _method: 'PUT',
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => close(),
        });
    } else {
        form.post(route('admin.players.store'), {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => close(),
        });
    }
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

        <div class="p-2 lg:p-4">
            <div class="mx-auto max-w-4xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-gray-900">Jogadores</h3>
                        <div class="flex items-center gap-2">
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

                    <DataTable :columns="columns" :rows="players" numbered :row-class="rowClass"
                        empty-message="Nenhum jogador cadastrado.">
                        <template #cell-name="{ row }">
                            <div class="flex items-center gap-3">
                                <img v-if="row.photo_front" :src="row.photo_front" class="h-8 w-8 rounded-full object-cover" />
                                <div v-else class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-200 text-xs font-bold text-gray-500">
                                    {{ row.name.charAt(0) }}
                                </div>
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

        <!-- Modal Criar/Editar -->
        <DialogModal :show="showModal" @close="close">
            <template #title>{{ isConverting ? 'Converter convidado' : isEditing ? 'Editar jogador' : 'Criar jogador' }}</template>

            <template #content>
                <div class="space-y-4">
                    <div>
                        <InputLabel for="pl-name" value="Nome" />
                        <TextInput id="pl-name" v-model="form.name" type="text" class="mt-1 block w-full" autofocus />
                        <InputError :message="form.errors.name" class="mt-2" />
                    </div>

                    <div>
                        <InputLabel for="pl-phone" value="Telefone" />
                        <TextInput id="pl-phone" v-model="form.phone" type="text" class="mt-1 block w-full" placeholder="5511999999999" />
                        <InputError :message="form.errors.phone" class="mt-2" />
                    </div>

                    <div>
                        <InputLabel for="pl-position" value="Posição" />
                        <select id="pl-position" v-model="form.position"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option v-for="pos in positions" :key="pos.value" :value="pos.value">
                                {{ pos.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.position" class="mt-2" />
                    </div>

                    <div v-if="isEditing">
                        <InputLabel for="pl-ability" value="Habilidade (1-10)" />
                        <div class="mt-1 flex items-center gap-3">
                            <input id="pl-ability" type="range" min="1" max="10" v-model.number="form.ability"
                                class="h-2 w-full cursor-pointer appearance-none rounded-lg bg-gray-200 accent-indigo-600" />
                            <span class="w-8 text-center text-lg font-bold text-indigo-600">{{ form.ability }}</span>
                        </div>
                        <InputError :message="form.errors.ability" class="mt-2" />
                    </div>

                    <div v-if="!isEditing && !isConverting">
                        <InputLabel for="pl-password" value="Senha" />
                        <TextInput id="pl-password" v-model="form.password" type="password" class="mt-1 block w-full" />
                        <InputError :message="form.errors.password" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="button" @click="form.active = !form.active"
                            :class="[
                                'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                form.active ? 'bg-indigo-600' : 'bg-gray-200',
                            ]">
                            <span :class="[
                                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                form.active ? 'translate-x-5' : 'translate-x-0',
                            ]" />
                        </button>
                        <InputLabel value="Ativo" class="cursor-pointer" @click="form.active = !form.active" />
                    </div>

                    <!-- Photo Front -->
                    <div>
                        <InputLabel value="Foto Frente" />
                        <input type="file" accept="image/jpeg,image/png" @change="onFileChange('photo_front', $event)"
                            class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100" />
                        <InputError :message="form.errors.photo_front" class="mt-2" />
                        <img v-if="photoFrontPreview" :src="photoFrontPreview" class="mt-2 h-32 w-auto rounded-lg object-cover" />
                    </div>

                    <!-- Photo Side -->
                    <div>
                        <InputLabel value="Foto Lado" />
                        <input type="file" accept="image/jpeg,image/png" @change="onFileChange('photo_side', $event)"
                            class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100" />
                        <InputError :message="form.errors.photo_side" class="mt-2" />
                        <img v-if="photoSidePreview" :src="photoSidePreview" class="mt-2 h-32 w-auto rounded-lg object-cover" />
                    </div>
                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="close">Cancelar</SecondaryButton>
                <PrimaryButton class="ms-3" :disabled="form.processing" @click="submit">
                    {{ isConverting ? 'Converter' : isEditing ? 'Atualizar' : 'Salvar' }}
                </PrimaryButton>
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
