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
    <div class="rounded-xl bg-white p-1 lg:p-4 shadow text-center">
        <p class="text-gray-900 font-semibold">{{ roundText }} - {{ pickText }}</p>
        <p class="text-lg font-semibold text-gray-900" v-if="status === 'drafting'">
            {{ isMyTurn ? 'Sua vez' : `${turnCaptainName || 'Capitão'} escolhendo...` }}
        </p>
        <p v-else class="text-lg font-semibold text-green-700">Draft finalizado</p>
    </div>
</template>
