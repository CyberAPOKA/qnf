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
    { key: 'active', label: 'Ativo', align: 'center' },
    { key: 'actions', label: '', align: 'center' },
];

const rowClass = (row) => (row.active ? '' : 'opacity-50');

// --- Modal state ---
const showModal = ref(false);
const editingPlayer = ref(null);
const isEditing = computed(() => editingPlayer.value !== null);

const form = useForm({
    name: '',
    phone: '',
    position: 'winger',
    password: '',
    active: true,
    photo_front: null,
    photo_side: null,
});

const photoFrontPreview = ref(null);
const photoSidePreview = ref(null);

const openCreate = () => {
    editingPlayer.value = null;
    form.reset();
    form.clearErrors();
    photoFrontPreview.value = null;
    photoSidePreview.value = null;
    showModal.value = true;
};

const openEdit = (player) => {
    editingPlayer.value = player;
    form.clearErrors();
    form.name = player.name;
    form.phone = player.phone;
    form.position = player.position;
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
    if (isEditing.value) {
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
                        <button @click="openCreate"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                            Criar jogador
                        </button>
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
                        <template #cell-active="{ row }">
                            <span :class="[
                                'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                row.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700',
                            ]">
                                {{ row.active ? 'Sim' : 'Não' }}
                            </span>
                        </template>
                        <template #cell-actions="{ row }">
                            <button @click="openEdit(row)" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                                Editar
                            </button>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <!-- Modal Criar/Editar -->
        <DialogModal :show="showModal" @close="close">
            <template #title>{{ isEditing ? 'Editar jogador' : 'Criar jogador' }}</template>

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

                    <div v-if="!isEditing">
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
                    {{ isEditing ? 'Atualizar' : 'Salvar' }}
                </PrimaryButton>
            </template>
        </DialogModal>
    </AppLayout>
</template>
