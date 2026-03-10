<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import FormSection from '@/Components/FormSection.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const user = usePage().props.auth.user;

const form = useForm({
    whatsapp_notifications: user.whatsapp_notifications,
});

const submit = () => {
    form.put(route('profile.update-whatsapp-notifications'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <FormSection @submitted="submit">
        <template #title>
            Notificações WhatsApp
        </template>

        <template #description>
            Escolha se deseja receber notificações no seu WhatsApp pessoal.
        </template>

        <template #form>
            <div class="col-span-6 sm:col-span-4">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        v-model="form.whatsapp_notifications"
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                    />
                    <span class="text-gray-700">
                        Receber mensagens no chat privado do WhatsApp
                    </span>
                </label>
                <p class="mt-2 text-sm text-gray-500">
                    Ex: avisos de sorteio de capitão, lembretes de draft, etc.
                </p>
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
