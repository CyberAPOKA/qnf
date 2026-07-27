<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const user = usePage().props.auth.user;

const form = useForm({
    instagram_username: user.instagram_username ?? '',
});

const previewUrl = computed(() => {
    const value = String(form.instagram_username || '').trim().replace(/^@/, '').toLowerCase();
    if (!value) return null;
    return `https://instagram.com/${value}`;
});

const submit = () => {
    form.put(route('profile.update-instagram'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <FormSection @submitted="submit">
        <template #title>
            Instagram
        </template>

        <template #description>
            Informe seu username para ser marcado automaticamente nos posts e stories do QNF.
        </template>

        <template #form>
            <div class="col-span-6 sm:col-span-4">
                <InputLabel for="instagram_username" value="Username do Instagram" />
                <TextInput
                    id="instagram_username"
                    v-model="form.instagram_username"
                    type="text"
                    class="mt-1 block w-full"
                    placeholder="@usuario ou URL do perfil"
                />
                <p v-if="previewUrl" class="mt-2 text-sm text-gray-500">
                    Perfil:
                    <a :href="previewUrl" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">
                        {{ previewUrl }}
                    </a>
                </p>
                <InputError :message="form.errors.instagram_username" class="mt-2" />
            </div>
        </template>

        <template #actions>
            <ActionMessage :on="form.recentlySuccessful" class="me-3">
                Salvo.
            </ActionMessage>

            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                Salvar
            </PrimaryButton>
        </template>
    </FormSection>
</template>
