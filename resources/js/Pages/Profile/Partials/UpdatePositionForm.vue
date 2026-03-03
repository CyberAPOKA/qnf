<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const user = usePage().props.auth.user;

const isLinePlayer = user.position !== 'goalkeeper';

const positionOptions = [
    { value: 'fixed', label: 'Fixo' },
    { value: 'winger', label: 'Ala' },
    { value: 'pivot', label: 'Pivô' },
];

const form = useForm({
    position: user.position,
});

const submit = () => {
    form.put(route('profile.update-position'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <FormSection @submitted="submit">
        <template #title>
            Posição
        </template>

        <template #description>
            Selecione a sua posição em quadra.
        </template>

        <template #form>
            <div v-if="isLinePlayer" class="col-span-6 sm:col-span-4">
                <InputLabel for="position" value="Posição" />
                <select
                    id="position"
                    v-model="form.position"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option v-for="opt in positionOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>
                <InputError :message="form.errors.position" class="mt-2" />
            </div>

            <div v-else class="col-span-6 sm:col-span-4">
                <InputLabel value="Posição" />
                <p class="mt-1 text-sm text-gray-600">Goleiro (não pode ser alterado)</p>
            </div>
        </template>

        <template v-if="isLinePlayer" #actions>
            <ActionMessage :on="form.recentlySuccessful" class="me-3">
                Salvo.
            </ActionMessage>

            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                Salvar
            </PrimaryButton>
        </template>
    </FormSection>
</template>
