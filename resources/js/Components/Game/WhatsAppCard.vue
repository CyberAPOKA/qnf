<script setup>
import { computed } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    message: {
        type: String,
        default: '',
    },
});

const whatsappLink = computed(() => {
    if (!props.message) return '#';
    return `https://wa.me/?text=${encodeURIComponent(props.message)}`;
});

const copyMessage = async () => {
    if (!props.message) return;
    await navigator.clipboard.writeText(props.message);
};
</script>

<template>
    <div class="rounded-xl bg-white p-4 shadow space-y-3">
        <h3 class="text-base font-semibold text-gray-900">Mensagem para WhatsApp</h3>
        <textarea
            :value="message"
            class="h-60 w-full rounded-lg border-gray-300 text-sm"
            readonly
        />
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <PrimaryButton class="w-full justify-center py-3" @click="copyMessage">
                Copiar
            </PrimaryButton>
            <a
                :href="whatsappLink"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center justify-center rounded-md bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-700"
            >
                Enviar no WhatsApp
            </a>
        </div>
    </div>
</template>
