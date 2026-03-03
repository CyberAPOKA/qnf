<script setup>
defineProps({
    statusLabel: String,
    playersCount: Number,
    round: {
        type: Number,
        default: null,
    },
});
</script>

<template>
    <div class="rounded-xl bg-white p-2 lg:p-4 shadow text-center">
        <p v-if="round" class="text-lg font-bold uppercase tracking-wide text-gray-900">
            Rodada {{ round }}
        </p>
        <p class="mt-2 text-sm text-gray-700">
            Inscritos: <span class="font-semibold">{{ playersCount }}/15</span>
        </p>
        <slot name="details" />

        <div class="mt-4 space-y-2">
            <slot name="actions" />
        </div>

        <slot name="footer" />
    </div>
</template>
