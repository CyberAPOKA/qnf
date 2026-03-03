<script setup>
defineProps({
    statusLabel: String,
    status: String,
    playersCount: Number,
    round: {
        type: Number,
        default: null,
    },
});
</script>

<template>
    <div class="rounded-xl bg-white p-2 lg:p-4 shadow text-center">
        <p v-if="round" class="text-2xl font-bold uppercase tracking-wide text-gray-900">
            Rodada {{ round }}
        </p>
        <p v-if="status !== 'scheduled'" class="mt-2 text-sm text-gray-700">
            Inscritos: <span class="font-semibold">{{ playersCount }}/15</span>
        </p>
        <slot name="details" />

        <div class="mt-2 space-y-2">
            <slot name="actions" />
        </div>

        <slot name="footer" />
    </div>
</template>
