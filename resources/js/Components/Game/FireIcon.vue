<script setup>
defineProps({
    streak: { type: Number, default: 0 },
    threshold: { type: Number, default: 3 },
});
</script>

<template>
    <i v-if="streak >= threshold" class="fa-solid fa-fire qnf-fire"></i>
</template>

<style scoped>
.qnf-fire {
    color: #ff3b30;
    filter: drop-shadow(0 0 10px rgba(255, 90, 0, .75));
    animation: qnfFlame .55s ease-in-out infinite alternate;
}

@keyframes qnfFlame {
    0% {
        transform: translateY(1px) scale(.98) rotate(-6deg);
        filter: drop-shadow(0 0 8px rgba(255, 110, 0, .65));
    }

    100% {
        transform: translateY(-2px) scale(1.08) rotate(6deg);
        filter: drop-shadow(0 0 14px rgba(255, 180, 0, .85));
    }
}

@media (prefers-reduced-motion: reduce) {
    .qnf-fire {
        animation: none !important;
    }
}
</style>
