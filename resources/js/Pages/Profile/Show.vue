<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdatePositionForm from '@/Pages/Profile/Partials/UpdatePositionForm.vue';
import UpdateWhatsAppNotificationsForm from '@/Pages/Profile/Partials/UpdateWhatsAppNotificationsForm.vue';

defineProps({
    confirmsTwoFactorAuthentication: Boolean,
    sessions: Array,
});
</script>

<template>
    <AppLayout title="Perfil">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Perfil
            </h2>
        </template>

        <div>
            <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8 space-y-10 sm:space-y-0">
                <UpdatePositionForm />

                <SectionBorder />

                <UpdateWhatsAppNotificationsForm />

                <SectionBorder />

                <div v-if="$page.props.jetstream.canUpdatePassword">
                    <UpdatePasswordForm />

                    <SectionBorder />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
