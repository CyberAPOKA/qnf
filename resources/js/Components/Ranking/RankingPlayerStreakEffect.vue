<script setup lang="ts">
import { computed } from 'vue'
import type { RankingStreakType } from '@/Components/Ranking/types'

const props = defineProps<{
    type: RankingStreakType
}>()

const videoSrc = computed(() => {
    if (props.type === 'fire') {
        return '/assets/streak/flames.webm'
    }

    if (props.type === 'ice') {
        return '/assets/streak/snowflakes.webm'
    }

    if (props.type === 'purple') {
        return '/assets/streak/lightning.webm'
    }

    if (props.type === 'skulls') {
        return '/assets/streak/skulls.webm'
    }

    if (props.type === 'dragon') {
        return '/assets/streak/dragon.webm'
    }

    return null
})
</script>

<template>
    <div
        v-if="type && videoSrc"
        class="ranking-streak"
        :class="`ranking-streak--${type}`"
        aria-hidden="true"
    >
        <video
            class="ranking-streak__video"
            autoplay
            muted
            loop
            playsinline
            preload="metadata"
        >
            <source :src="videoSrc" type="video/webm">
        </video>

        <div class="ranking-streak__overlay" />
        <div class="ranking-streak__particles" />
        <div class="ranking-streak__energy" />
    </div>
</template>

<style scoped>
.ranking-streak {
    position: absolute;
    inset: 0;
    z-index: 1;
    overflow: hidden;
    pointer-events: none;
}

.ranking-streak__video {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.62;
    mix-blend-mode: screen;
}

.ranking-streak__overlay {
    position: absolute;
    inset: 0;
}

.ranking-streak__particles {
    position: absolute;
    inset: 0;
    opacity: 0.7;
}

.ranking-streak__energy {
    position: absolute;
    inset: 0;
}

.ranking-streak--fire .ranking-streak__video {
    opacity: 0.72;
}

.ranking-streak--fire .ranking-streak__overlay {
    background:
        radial-gradient(circle at 18% 70%, rgba(255, 106, 0, 0.5), transparent 34%),
        linear-gradient(90deg, rgba(255, 106, 0, 0.34), rgba(245, 158, 11, 0.08) 48%, transparent 78%);
}

.ranking-streak--fire .ranking-streak__particles {
    background-image:
        radial-gradient(circle, rgba(255, 211, 102, 0.95) 1px, transparent 1px),
        radial-gradient(circle, rgba(255, 95, 31, 0.7) 1px, transparent 1px);
    background-position: 0 0, 7px 9px;
    background-size: 22px 22px, 31px 31px;
    mask-image: linear-gradient(to right, black, transparent 78%);
}

.ranking-streak--fire .ranking-streak__energy {
    background:
        linear-gradient(
            115deg,
            transparent 0 20%,
            rgba(255, 106, 0, 0.24) 20.2% 20.8%,
            transparent 21% 35%,
            rgba(255, 191, 0, 0.18) 35.2% 35.8%,
            transparent 36%
        );
}

.ranking-streak--ice .ranking-streak__video {
    opacity: 0.82;
}

.ranking-streak--ice .ranking-streak__overlay {
    background:
        radial-gradient(circle at 22% 55%, rgba(125, 211, 252, 0.42), transparent 38%),
        linear-gradient(90deg, rgba(14, 116, 144, 0.42), rgba(56, 189, 248, 0.12) 50%, transparent 80%);
}

.ranking-streak--ice .ranking-streak__particles {
    background-image:
        radial-gradient(circle, rgba(224, 242, 254, 0.95) 1px, transparent 1px),
        radial-gradient(circle, rgba(125, 211, 252, 0.7) 1px, transparent 1px);
    background-position: 0 0, 8px 10px;
    background-size: 20px 20px, 30px 30px;
    mask-image: linear-gradient(to right, black, transparent 80%);
}

.ranking-streak--ice .ranking-streak__energy {
    background:
        linear-gradient(
            115deg,
            transparent 0 18%,
            rgba(186, 230, 253, 0.28) 18.2% 18.8%,
            transparent 19% 36%,
            rgba(56, 189, 248, 0.22) 36.2% 36.8%,
            transparent 37% 56%,
            rgba(14, 165, 233, 0.18) 56.2% 56.8%,
            transparent 57%
        );
}

.ranking-streak--purple .ranking-streak__video {
    opacity: 0.78;
}

.ranking-streak--purple .ranking-streak__overlay {
    background:
        radial-gradient(circle at 30% 50%, rgba(168, 85, 247, 0.56), transparent 40%),
        linear-gradient(90deg, rgba(88, 28, 135, 0.52), rgba(76, 29, 149, 0.18) 55%, transparent 82%);
}

.ranking-streak--purple .ranking-streak__particles {
    background-image:
        radial-gradient(circle, rgba(216, 180, 254, 0.95) 1px, transparent 1px),
        radial-gradient(circle, rgba(139, 92, 246, 0.72) 1px, transparent 1px);
    background-position: 0 0, 9px 11px;
    background-size: 19px 19px, 28px 28px;
    mask-image: linear-gradient(to right, black, transparent 85%);
}

.ranking-streak--purple .ranking-streak__energy {
    background:
        linear-gradient(
            116deg,
            transparent 0 18%,
            rgba(192, 132, 252, 0.32) 18.2% 18.8%,
            transparent 19% 37%,
            rgba(124, 58, 237, 0.28) 37.2% 37.8%,
            transparent 38% 58%,
            rgba(168, 85, 247, 0.2) 58.2% 58.8%,
            transparent 59%
        );
    filter: blur(0.4px);
}

.ranking-streak--skulls .ranking-streak__video {
    opacity: 0.8;
}

.ranking-streak--skulls .ranking-streak__overlay {
    background:
        radial-gradient(circle at 24% 55%, rgba(148, 163, 184, 0.35), transparent 40%),
        linear-gradient(90deg, rgba(30, 41, 59, 0.62), rgba(71, 85, 105, 0.18) 52%, transparent 82%);
}

.ranking-streak--skulls .ranking-streak__particles {
    background-image:
        radial-gradient(circle, rgba(226, 232, 240, 0.9) 1px, transparent 1px),
        radial-gradient(circle, rgba(148, 163, 184, 0.65) 1px, transparent 1px);
    background-position: 0 0, 9px 11px;
    background-size: 20px 20px, 30px 30px;
    mask-image: linear-gradient(to right, black, transparent 84%);
}

.ranking-streak--skulls .ranking-streak__energy {
    background:
        linear-gradient(
            116deg,
            transparent 0 17%,
            rgba(203, 213, 225, 0.22) 17.2% 17.8%,
            transparent 18% 38%,
            rgba(100, 116, 139, 0.2) 38.2% 38.8%,
            transparent 39%
        );
}

.ranking-streak--dragon .ranking-streak__video {
    opacity: 0.8;
}

.ranking-streak--dragon .ranking-streak__overlay {
    background:
        radial-gradient(circle at 26% 52%, rgba(220, 38, 38, 0.48), transparent 40%),
        linear-gradient(90deg, rgba(127, 29, 29, 0.55), rgba(185, 28, 28, 0.16) 52%, transparent 82%);
}

.ranking-streak--dragon .ranking-streak__particles {
    background-image:
        radial-gradient(circle, rgba(254, 202, 202, 0.95) 1px, transparent 1px),
        radial-gradient(circle, rgba(239, 68, 68, 0.7) 1px, transparent 1px);
    background-position: 0 0, 8px 10px;
    background-size: 18px 18px, 28px 28px;
    mask-image: linear-gradient(to right, black, transparent 84%);
}

.ranking-streak--dragon .ranking-streak__energy {
    background:
        linear-gradient(
            116deg,
            transparent 0 16%,
            rgba(248, 113, 113, 0.3) 16.2% 16.8%,
            transparent 17% 36%,
            rgba(220, 38, 38, 0.24) 36.2% 36.8%,
            transparent 37% 58%,
            rgba(185, 28, 28, 0.18) 58.2% 58.8%,
            transparent 59%
        );
}

@media (max-width: 950px) {
    .ranking-streak__video {
        opacity: 0.45;
    }
}

@media (prefers-reduced-motion: reduce) {
    .ranking-streak__video {
        display: none;
    }
}
</style>
