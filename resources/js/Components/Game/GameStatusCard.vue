<script setup>
defineProps({
    statusLabel: String,
    playersCount: Number,
    countdown: {
        type: Number,
        default: null,
    },
    isCountingDown: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div class="rounded-xl bg-white p-4 shadow">
        <p class="text-sm text-gray-500">Status</p>
        <p class="mt-1 text-lg font-semibold text-gray-900">{{ statusLabel }}</p>
        <p class="mt-2 text-sm text-gray-700">
            Inscritos: <span class="font-semibold">{{ playersCount }}/15</span>
        </p>
        <slot name="details" />

        <div v-if="isCountingDown" class="mt-4 rounded-lg bg-amber-50 p-3 text-center">
            <p class="text-sm font-semibold text-amber-800">DRAFT EM</p>
            <p class="text-3xl font-bold text-amber-600">{{ countdown }}s</p>
        </div>

        <div class="mt-4 space-y-2">
            <slot name="actions" />
        </div>

        <slot name="footer" />
    </div>
</template>
