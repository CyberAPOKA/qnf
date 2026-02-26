<script setup>
import { ref } from 'vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    gameId: Number,
});

const show = ref(false);

const form = useForm({
    name: '',
    position: 'winger',
    enroll: true,
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
    form.post(route('games.store-guest', props.gameId), {
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
        <template #title>Adicionar convidado</template>

        <template #content>
            <div class="space-y-4">
                <div>
                    <InputLabel for="guest-name" value="Nome" />
                    <TextInput id="guest-name" v-model="form.name" type="text" class="mt-1 block w-full"
                        autofocus />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="guest-position" value="Posição" />
                    <select id="guest-position" v-model="form.position"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option v-for="pos in positions" :key="pos.value" :value="pos.value">
                            {{ pos.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.position" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" @click="form.enroll = !form.enroll"
                        :class="[
                            'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                            form.enroll ? 'bg-indigo-600' : 'bg-gray-200',
                        ]">
                        <span :class="[
                            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                            form.enroll ? 'translate-x-5' : 'translate-x-0',
                        ]" />
                    </button>
                    <InputLabel value="Inscrever convidado ao criar" class="cursor-pointer" @click="form.enroll = !form.enroll" />
                </div>

                <InputError :message="form.errors.guest" class="mt-2" />
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
