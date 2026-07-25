<script setup>
defineProps({ clip: { type: Object, required: true } });
</script>

<template>
    <div class="space-y-1 min-w-0">
        <p class="text-[11px] font-medium text-gray-600 flex items-center gap-1.5 truncate">
            <span v-if="clip.camera_tag" class="rounded bg-red-600 text-white text-[10px] font-bold px-1.5 py-0.5">
                {{ clip.camera_tag }}
            </span>
            <span class="truncate">{{ clip.user_name || clip.user?.name }}</span>
        </p>
        <video
            :src="clip.preview_url || clip.url"
            controls
            playsinline
            preload="metadata"
            class="w-full h-32 rounded-lg bg-black object-cover"
        />
    </div>
</template>
