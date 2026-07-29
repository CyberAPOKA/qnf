<script setup>
import { computed } from 'vue';
import FireIcon from '@/Components/Game/FireIcon.vue';
import { resolveStreakTier } from '@/composables/streakTiers';

const props = defineProps({
    name: { type: String, required: true },
    streak: { type: Number, default: 0 },
    nameClass: {
        type: String,
        default: 'text-sm md:text-base lg:text-lg font-medium text-gray-900',
    },
});

const tier = computed(() => resolveStreakTier(props.streak));
const hasSideIcons = computed(() => tier.value?.icon === 'fire');
</script>

<template>
    <div
        class="relative flex flex-col items-center"
        :class="tier?.icon === 'crown' ? 'pt-3' : ''"
    >
        <i
            v-if="tier?.icon === 'crown'"
            class="fa-solid fa-crown qnf-streak-crown"
            aria-hidden="true"
        />

        <div
            class="flex items-center justify-center"
            :class="hasSideIcons ? 'lg:gap-1' : ''"
        >
            <FireIcon v-if="hasSideIcons" :streak="streak" />
            <span :class="nameClass">{{ name }}</span>
            <FireIcon v-if="hasSideIcons" :streak="streak" />
            <slot />
        </div>
    </div>
</template>

<style scoped>
.qnf-streak-crown {
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    font-size: 0.85rem;
    line-height: 1;
    color: #a855f7;
    filter:
        drop-shadow(0 0 6px rgba(168, 85, 247, 0.9))
        drop-shadow(0 0 14px rgba(109, 40, 217, 0.65));
    animation: qnfCrownBob 1.4s ease-in-out infinite;
    z-index: 1;
}

@keyframes qnfCrownBob {
    0%,
    100% {
        transform: translateX(-50%) translateY(0) rotate(-4deg);
        filter:
            drop-shadow(0 0 6px rgba(168, 85, 247, 0.85))
            drop-shadow(0 0 12px rgba(109, 40, 217, 0.55));
    }

    50% {
        transform: translateX(-50%) translateY(-2px) rotate(4deg);
        filter:
            drop-shadow(0 0 10px rgba(216, 70, 239, 0.95))
            drop-shadow(0 0 18px rgba(168, 85, 247, 0.75));
    }
}

@media (prefers-reduced-motion: reduce) {
    .qnf-streak-crown {
        animation: none !important;
    }
}
</style>
