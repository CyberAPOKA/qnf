<script setup>
defineProps({
    src: { type: String, default: null },
    initial: { type: String, default: '?' },
    alt: { type: String, default: '' },
    size: { type: String, default: 'md' },
});

const sizeClasses = {
    sm: { container: 'h-10 w-10', img: 'h-[300%] pt-6', fallback: 'h-10 w-10 text-sm' },
    md: { container: 'h-28 sm:h-24 lg:h-28', img: 'h-[300%] sm:h-[200%] lg:max-w-60 pt-28 sm:pt-12 md:pt-14', fallback: 'h-16 w-16 text-sm' },
    lg: { container: 'h-20 w-14 rounded-b-md', img: 'h-[200%] pt-8', fallback: 'h-14 w-14 text-lg' },
};
</script>

<template>
    <div v-if="src" class="flex items-center justify-center overflow-hidden" :class="sizeClasses[size]?.container">
        <img :src="src" :alt="alt"
            class="w-full object-cover object-top" :class="sizeClasses[size]?.img" />
    </div>
    <div v-else class="flex items-center justify-center">
        <span class="flex text-center items-center justify-center rounded-full bg-gray-300 font-bold text-white"
            :class="sizeClasses[size]?.fallback">
            {{ initial }}
        </span>
    </div>
</template>
