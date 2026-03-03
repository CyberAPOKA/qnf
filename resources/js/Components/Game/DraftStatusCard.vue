<script setup>
defineProps({
    roundText: {
        type: String,
        required: true,
    },
    pickText: {
        type: String,
        required: true,
    },
    status: {
        type: String,
        required: true,
    },
    isMyTurn: {
        type: Boolean,
        default: false,
    },
    turnCaptainName: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <div class="rounded-xl bg-white p-2 lg:p-4 shadow">
        <p class="text-sm text-gray-500">{{ roundText }} - {{ pickText }}</p>
        <p class="mt-2 text-lg font-semibold text-gray-900" v-if="status === 'drafting'">
            {{ isMyTurn ? 'Sua vez' : `${turnCaptainName || 'Capitão'} escolhendo...` }}
        </p>
        <p v-else class="mt-2 text-lg font-semibold text-green-700">Draft finalizado</p>
    </div>
</template>
