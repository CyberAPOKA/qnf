<script setup>
import { computed, ref, shallowRef } from 'vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const positions = [
    { value: 'goalkeeper', label: 'Goleiro' },
    { value: 'fixed', label: 'Fixo' },
    { value: 'winger', label: 'Ala' },
    { value: 'pivot', label: 'Pivô' },
];

const show = ref(false);
const mode = ref('create');
const targetPlayer = ref(null);
const form = shallowRef(null);
const photoFrontPreview = ref(null);
const photoSidePreview = ref(null);

const isCreate = computed(() => mode.value === 'create');
const isEdit = computed(() => mode.value === 'edit');
const isConvert = computed(() => mode.value === 'convert');

const modalTitle = computed(() => {
    if (isConvert.value) {
        return 'Converter convidado';
    }

    if (isEdit.value) {
        return 'Editar jogador';
    }

    return 'Criar jogador';
});

const submitLabel = computed(() => {
    if (isConvert.value) {
        return 'Converter';
    }

    if (isEdit.value) {
        return 'Atualizar';
    }

    return 'Salvar';
});

const defaultFormData = () => ({
    name: '',
    phone: '',
    position: 'winger',
    ability: 5,
    password: '',
    active: true,
    photo_front: null,
    photo_side: null,
});

const resolveEndpoint = (formMode, player) => {
    if (formMode === 'edit' && player) {
        return route('admin.players.update', player.id);
    }

    if (formMode === 'convert' && player) {
        return route('admin.players.convert-guest', player.id);
    }

    return route('admin.players.store');
};

const buildForm = (formMode, player = null) => {
    const data = defaultFormData();

    if (formMode === 'edit' && player) {
        data.name = player.name;
        data.phone = player.phone;
        data.position = player.position;
        data.ability = player.ability ?? 5;
        data.active = player.active;
    }

    if (formMode === 'convert' && player) {
        data.name = player.name;
        data.position = player.position;
        data.phone = '';
        data.password = '';
        data.active = true;
    }

    return useForm(data).withPrecognition('post', resolveEndpoint(formMode, player));
};

const open = (formMode, player = null) => {
    mode.value = formMode;
    targetPlayer.value = player;
    photoFrontPreview.value = player?.photo_front ?? null;
    photoSidePreview.value = player?.photo_side ?? null;
    form.value = buildForm(formMode, player);
    show.value = true;
};

const close = () => {
    show.value = false;
    mode.value = 'create';
    targetPlayer.value = null;
    form.value = null;
    photoFrontPreview.value = null;
    photoSidePreview.value = null;
};

const onFileChange = (field, event) => {
    const file = event.target.files?.[0];

    if (! file || ! form.value) {
        return;
    }

    form.value[field] = file;

    const reader = new FileReader();
    reader.onload = (e) => {
        if (field === 'photo_front') {
            photoFrontPreview.value = e.target.result;
        } else {
            photoSidePreview.value = e.target.result;
        }
    };
    reader.readAsDataURL(file);

    form.value.validateFiles?.();
    form.value.validate(field);
};

const normalizePhone = (value) => (value ?? '').replace(/\D/g, '');

const onPhoneChange = () => {
    if (! form.value) {
        return;
    }

    form.value.phone = normalizePhone(form.value.phone);
    form.value.validate('phone');
};

const resolveFieldsToValidate = () => {
    const fields = ['name', 'phone', 'position'];

    if (isCreate.value) {
        fields.push('password');
    }

    if (isEdit.value) {
        fields.push('ability');
    }

    return fields;
};

const sendForm = () => {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => close(),
    };

    if (isEdit.value) {
        form.value.post(route('admin.players.update', targetPlayer.value.id), {
            ...options,
            _method: 'put',
        });

        return;
    }

    if (isConvert.value) {
        form.value.post(route('admin.players.convert-guest', targetPlayer.value.id), options);

        return;
    }

    form.value.post(route('admin.players.store'), options);
};

const submit = () => {
    if (! form.value || form.value.processing || form.value.validating) {
        return;
    }

    form.value.phone = normalizePhone(form.value.phone);

    const fields = resolveFieldsToValidate();

    form.value.touch(fields);

    form.value.validate({
        only: fields,
        onSuccess: () => sendForm(),
    });
};

defineExpose({
    open,
    openCreate: () => open('create'),
    openEdit: (player) => open('edit', player),
    openConvert: (player) => open('convert', player),
});
</script>

<template>
    <DialogModal :show="show" @close="close">
        <template #title>{{ modalTitle }}</template>

        <template #content>
            <form v-if="form" class="space-y-4" @submit.prevent="submit">
                <div>
                    <InputLabel for="player-name" value="Nome" required />
                    <TextInput
                        id="player-name"
                        v-model="form.name"
                        type="text"
                        class="mt-1 block w-full"
                        required
                        autofocus
                        @change="form.validate('name')"
                    />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="player-phone" value="Telefone" required />
                    <TextInput
                        id="player-phone"
                        v-model="form.phone"
                        type="text"
                        inputmode="tel"
                        class="mt-1 block w-full"
                        placeholder="+55 51 9929-4672"
                        required
                        @change="onPhoneChange"
                    />
                    <p class="mt-1 text-xs text-gray-500">
                        Aceita com ou sem máscara. Será salvo como 555199294672.
                    </p>
                    <InputError :message="form.errors.phone" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="player-position" value="Posição" required />
                    <select
                        id="player-position"
                        v-model="form.position"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        required
                        @change="form.validate('position')"
                    >
                        <option v-for="pos in positions" :key="pos.value" :value="pos.value">
                            {{ pos.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.position" class="mt-2" />
                </div>

                <div v-if="isEdit">
                    <InputLabel for="player-ability" value="Habilidade (1-10)" />
                    <div class="mt-1 flex items-center gap-3">
                        <input
                            id="player-ability"
                            v-model.number="form.ability"
                            type="range"
                            min="1"
                            max="10"
                            class="h-2 w-full cursor-pointer appearance-none rounded-lg bg-gray-200 accent-indigo-600"
                            @change="form.validate('ability')"
                        />
                        <span class="w-8 text-center text-lg font-bold text-indigo-600">{{ form.ability }}</span>
                    </div>
                    <InputError :message="form.errors.ability" class="mt-2" />
                </div>

                <div v-if="isCreate">
                    <InputLabel for="player-password" value="Senha" required />
                    <TextInput
                        id="player-password"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        @change="form.validate('password')"
                    />
                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div v-if="isCreate || isEdit" class="flex items-center gap-3">
                    <button
                        type="button"
                        class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        :class="form.active ? 'bg-indigo-600' : 'bg-gray-200'"
                        @click="form.active = !form.active"
                    >
                        <span
                            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                            :class="form.active ? 'translate-x-5' : 'translate-x-0'"
                        />
                    </button>
                    <InputLabel value="Ativo" class="cursor-pointer" @click="form.active = !form.active" />
                </div>

                <div>
                    <InputLabel value="Foto Frente" />
                    <input
                        type="file"
                        accept="image/jpeg,image/png"
                        class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
                        @change="onFileChange('photo_front', $event)"
                    />
                    <InputError :message="form.errors.photo_front" class="mt-2" />
                    <img
                        v-if="photoFrontPreview"
                        :src="photoFrontPreview"
                        class="mt-2 h-32 w-auto rounded-lg object-cover"
                        alt="Prévia da foto frente"
                    />
                </div>

                <div>
                    <InputLabel value="Foto Lado" />
                    <input
                        type="file"
                        accept="image/jpeg,image/png"
                        class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
                        @change="onFileChange('photo_side', $event)"
                    />
                    <InputError :message="form.errors.photo_side" class="mt-2" />
                    <img
                        v-if="photoSidePreview"
                        :src="photoSidePreview"
                        class="mt-2 h-32 w-auto rounded-lg object-cover"
                        alt="Prévia da foto lado"
                    />
                </div>
            </form>
        </template>

        <template #footer>
            <SecondaryButton @click="close">Cancelar</SecondaryButton>
            <PrimaryButton
                class="ms-3"
                :disabled="!form || form.processing || form.validating"
                @click="submit"
            >
                {{ submitLabel }}
            </PrimaryButton>
        </template>
    </DialogModal>
</template>
