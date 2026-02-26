<script setup>
import { computed } from 'vue';

const props = defineProps({
    position: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
});

const iconSrc = computed(() => `/assets/svgs/${props.position}.svg`);
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700">
        <img :src="iconSrc" :alt="label" class="h-3.5 w-3.5" />
        {{ label }}
    </span>
</template>
