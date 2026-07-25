<script setup>
defineProps({
    jobs: { type: Array, default: () => [] },
    processing: Boolean,
});
</script>

<template>
    <section class="rounded-2xl bg-white shadow p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Uploads locais</h2>
            <i v-if="processing" class="fa-solid fa-spinner fa-spin text-indigo-500" />
        </div>
        <p v-if="!jobs.length" class="text-sm text-gray-500">Fila vazia.</p>
        <ul v-else class="space-y-2 max-h-48 overflow-y-auto">
            <li v-for="job in jobs" :key="job.id" class="text-xs flex justify-between gap-2">
                <span>#{{ job.sequence }} · {{ job.saveRequestUuids?.length ? 'SAVE prioritário' : 'contínuo' }}</span>
                <span :class="job.status === 'permanent_failed' ? 'text-red-600' : 'text-gray-500'">
                    {{ job.status }}<template v-if="job.attempts"> · {{ job.attempts }} tent.</template>
                </span>
            </li>
        </ul>
    </section>
</template>
