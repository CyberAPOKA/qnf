<script setup>
defineProps({
    cameras: { type: Array, default: () => [] },
    ownId: [String, Number],
    v2: Boolean,
});
</script>

<template>
    <section class="rounded-2xl bg-white shadow p-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Gravando agora</h2>
        <ul v-if="cameras.length" class="space-y-2">
            <li v-for="camera in cameras" :key="camera.uuid || camera.recorder_id" class="flex justify-between text-sm">
                <span class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0" />
                    <span class="rounded bg-red-600 text-white text-[10px] font-bold px-1.5 py-0.5">
                        {{ camera.camera_tag }}
                    </span>
                    <span class="truncate">{{ camera.user_name || camera.user?.name }}</span>
                    <span v-if="(camera.uuid || camera.recorder_id) === ownId" class="text-xs text-gray-400">(você)</span>
                </span>
                <span v-if="v2" class="text-[10px] text-gray-500">{{ camera.status || 'online' }}</span>
            </li>
        </ul>
        <p v-else class="text-sm text-gray-500">Nenhuma câmera gravando.</p>
    </section>
</template>
