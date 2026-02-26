<script setup>
import { ref } from 'vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const show = ref(false);

const form = useForm({
    name: '',
    phone: '',
    position: 'winger',
    password: '',
});

const positions = [
    { value: 'goalkeeper', label: 'Goleiro' },
    { value: 'fixed', label: 'Fixo' },
    { value: 'winger', label: 'Ala' },
    { value: 'pivot', label: 'Pivô' },
];

const open = () => {
    show.value = true;
};

const close = () => {
    show.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.post(route('admin.store-player'), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            close();
        },
    });
};

defineExpose({ open });
</script>

<template>
    <DialogModal :show="show" @close="close">
        <template #title>Criar jogador</template>

        <template #content>
            <div class="space-y-4">
                <div>
                    <InputLabel for="player-name" value="Nome" />
                    <TextInput id="player-name" v-model="form.name" type="text" class="mt-1 block w-full"
                        autofocus />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="player-phone" value="Telefone" />
                    <TextInput id="player-phone" v-model="form.phone" type="text" class="mt-1 block w-full"
                        placeholder="5511999999999" />
                    <InputError :message="form.errors.phone" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="player-position" value="Posição" />
                    <select id="player-position" v-model="form.position"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option v-for="pos in positions" :key="pos.value" :value="pos.value">
                            {{ pos.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.position" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="player-password" value="Senha" />
                    <TextInput id="player-password" v-model="form.password" type="password" class="mt-1 block w-full" />
                    <InputError :message="form.errors.password" class="mt-2" />
                </div>
            </div>
        </template>

        <template #footer>
            <SecondaryButton @click="close">Cancelar</SecondaryButton>
            <PrimaryButton class="ms-3" :disabled="form.processing" @click="submit">
                Salvar
            </PrimaryButton>
        </template>
    </DialogModal>
</template>
