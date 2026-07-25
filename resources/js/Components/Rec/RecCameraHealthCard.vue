<script setup>
defineProps({
    status: { type: String, default: 'warming_up' },
    label: { type: String, default: 'Aquecendo buffer' },
    colorClass: { type: String, default: 'bg-amber-100 text-amber-700 border-amber-200' },
    availableMs: { type: Number, default: 0 },
    pendingUploads: { type: Number, default: 0 },
    hasAudio: { type: Boolean, default: true },
});
</script>

<template>
    <section class="rounded-2xl bg-white shadow p-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Saúde desta câmera</h2>
            <span class="text-xs font-medium px-2 py-1 rounded-full border" :class="colorClass">{{ label }}</span>
        </div>
        <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div><dt class="text-[10px] text-gray-500">Buffer</dt><dd class="text-sm font-semibold">{{ Math.round(availableMs / 1000) }}s</dd></div>
            <div><dt class="text-[10px] text-gray-500">Pendentes</dt><dd class="text-sm font-semibold">{{ pendingUploads }}</dd></div>
            <div><dt class="text-[10px] text-gray-500">Áudio</dt><dd class="text-sm font-semibold">{{ hasAudio ? 'Sim' : 'Não' }}</dd></div>
        </dl>
    </section>
</template>
