<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    streak: number;
    minStreak?: number;
    maxStreak?: number | null;
}

const props = withDefaults(defineProps<Props>(), {
    minStreak: 7,
    maxStreak: 9,
});

const visible = computed(() => {
    if (props.streak < props.minStreak) {
        return false;
    }

    if (props.maxStreak !== null && props.streak > props.maxStreak) {
        return false;
    }

    return true;
});
</script>

<template>
    <div
        v-if="visible"
        class="thunder-streak"
        aria-hidden="true"
    >
        <div class="thunder-streak__fixed-effect" />

        <div class="thunder-streak__border-wrapper">
            <span class="thunder-streak__border thunder-streak__border--top" />
            <span class="thunder-streak__border thunder-streak__border--right" />
            <span class="thunder-streak__border thunder-streak__border--bottom" />
            <span class="thunder-streak__border thunder-streak__border--left" />
        </div>

        <div class="thunder-streak__video-wrapper">
            <video
                class="thunder-streak__video"
                autoplay
                loop
                muted
                playsinline
                preload="auto"
            >
                <source
                    src="/assets/streak/thunder.webm"
                    type="video/webm"
                >
            </video>
        </div>

        <img
            class="thunder-streak__icon"
            src="/assets/streak/thunder-icon.png"
            :title="`Thunderstorm! ${streak} vitórias seguidas`"
            alt=""
        >
    </div>
</template>

<style scoped>
.thunder-streak {
    position: absolute;
    inset: 0;
    z-index: 5;
    border-radius: inherit;
    pointer-events: none;
}

.thunder-streak__fixed-effect {
    position: absolute;
    inset: -3px;
    border-radius: inherit;
    box-shadow:
        0 0 6px rgba(92, 199, 255, 0.9),
        0 0 14px rgba(52, 132, 255, 0.75),
        0 0 30px rgba(88, 61, 255, 0.5);
    animation: thunder-fixed-flicker 2.4s infinite;
}

.thunder-streak__border-wrapper {
    position: absolute;
    inset: -3px;
    z-index: 4;
    overflow: hidden;
    border-radius: inherit;
}

.thunder-streak__border {
    position: absolute;
    display: block;
    filter:
        drop-shadow(0 0 4px rgba(255, 255, 255, 1))
        drop-shadow(0 0 8px rgba(72, 186, 255, 1))
        drop-shadow(0 0 12px rgba(75, 82, 255, 0.9));
}

.thunder-streak__border--top,
.thunder-streak__border--bottom {
    width: 45%;
    height: 3px;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(92, 138, 255, 0.25),
        #ffffff,
        #58c7ff,
        transparent
    );
}

.thunder-streak__border--left,
.thunder-streak__border--right {
    width: 3px;
    height: 45%;
    background: linear-gradient(
        180deg,
        transparent,
        rgba(92, 138, 255, 0.25),
        #ffffff,
        #58c7ff,
        transparent
    );
}

.thunder-streak__border--top {
    top: 0;
    left: -45%;
    animation: thunder-border-top 2.2s linear infinite;
}

.thunder-streak__border--right {
    top: -45%;
    right: 0;
    animation: thunder-border-right 2.2s linear infinite 0.55s;
}

.thunder-streak__border--bottom {
    right: -45%;
    bottom: 0;
    animation: thunder-border-bottom 2.2s linear infinite 1.1s;
}

.thunder-streak__border--left {
    bottom: -45%;
    left: 0;
    animation: thunder-border-left 2.2s linear infinite 1.65s;
}

.thunder-streak__video-wrapper {
    position: absolute;
    inset: -35px;
    z-index: 3;
    overflow: hidden;
    border-radius: calc(inherit + 35px);
    mix-blend-mode: screen;
}

.thunder-streak__video {
    width: 100%;
    height: 100%;
    object-fit: fill;
    opacity: 0.85;
    filter:
        brightness(1.15)
        contrast(1.25)
        saturate(1.4);
}

.thunder-streak__icon {
    position: absolute;
    top: -22px;
    right: -20px;
    z-index: 6;
    width: 58px;
    height: 58px;
    object-fit: contain;
    filter:
        drop-shadow(0 0 6px rgba(255, 255, 255, 0.9))
        drop-shadow(0 0 12px rgba(73, 174, 255, 0.9));
    animation: thunder-icon-pulse 1.8s ease-in-out infinite;
}

@keyframes thunder-border-top {
    0% {
        left: -45%;
        opacity: 0;
    }

    10% {
        opacity: 1;
    }

    90% {
        opacity: 1;
    }

    100% {
        left: 100%;
        opacity: 0;
    }
}

@keyframes thunder-border-right {
    0% {
        top: -45%;
        opacity: 0;
    }

    10% {
        opacity: 1;
    }

    90% {
        opacity: 1;
    }

    100% {
        top: 100%;
        opacity: 0;
    }
}

@keyframes thunder-border-bottom {
    0% {
        right: -45%;
        opacity: 0;
    }

    10% {
        opacity: 1;
    }

    90% {
        opacity: 1;
    }

    100% {
        right: 100%;
        opacity: 0;
    }
}

@keyframes thunder-border-left {
    0% {
        bottom: -45%;
        opacity: 0;
    }

    10% {
        opacity: 1;
    }

    90% {
        opacity: 1;
    }

    100% {
        bottom: 100%;
        opacity: 0;
    }
}

@keyframes thunder-fixed-flicker {
    0%,
    17%,
    19%,
    22%,
    64%,
    68%,
    100% {
        opacity: 0.75;
    }

    18%,
    21%,
    66% {
        opacity: 1;
    }

    45% {
        opacity: 0.45;
    }
}

@keyframes thunder-icon-pulse {
    0%,
    100% {
        transform: scale(1) rotate(-2deg);
        opacity: 0.9;
    }

    50% {
        transform: scale(1.12) rotate(2deg);
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .thunder-streak__fixed-effect,
    .thunder-streak__border,
    .thunder-streak__icon {
        animation: none;
    }

    .thunder-streak__video {
        display: none;
    }
}
</style>