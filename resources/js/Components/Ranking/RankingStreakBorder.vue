<script setup lang="ts">
import type { RankingStreakType } from '@/Components/Ranking/types'

defineProps<{
    type: RankingStreakType
}>()
</script>

<template>
    <div
        v-if="type"
        class="ranking-streak-border"
        :class="`ranking-streak-border--${type}`"
        aria-hidden="true"
    >
        <span class="ranking-streak-border__edge ranking-streak-border__edge--top" />
        <span class="ranking-streak-border__edge ranking-streak-border__edge--right" />
        <span class="ranking-streak-border__edge ranking-streak-border__edge--bottom" />
        <span class="ranking-streak-border__edge ranking-streak-border__edge--left" />
    </div>
</template>

<style scoped>
.ranking-streak-border {
    position: absolute;
    inset: 0;
    z-index: 12;
    overflow: hidden;
    pointer-events: none;
}

.ranking-streak-border__edge {
    position: absolute;
    display: block;
}

/* ── Purple (streak 5): thunder-style chase ── */
.ranking-streak-border--purple .ranking-streak-border__edge {
    filter:
        drop-shadow(0 0 4px rgba(255, 255, 255, 1))
        drop-shadow(0 0 8px rgba(192, 132, 252, 1))
        drop-shadow(0 0 12px rgba(168, 85, 247, 0.95));
}

.ranking-streak-border--purple .ranking-streak-border__edge--top,
.ranking-streak-border--purple .ranking-streak-border__edge--bottom {
    width: 45%;
    height: 3px;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(168, 85, 247, 0.3),
        #ffffff,
        #c084fc,
        transparent
    );
}

.ranking-streak-border--purple .ranking-streak-border__edge--left,
.ranking-streak-border--purple .ranking-streak-border__edge--right {
    width: 3px;
    height: 45%;
    background: linear-gradient(
        180deg,
        transparent,
        rgba(168, 85, 247, 0.3),
        #ffffff,
        #c084fc,
        transparent
    );
}

.ranking-streak-border--purple .ranking-streak-border__edge--top {
    top: 0;
    left: -45%;
    animation: streak-border-top 2.2s linear infinite;
}

.ranking-streak-border--purple .ranking-streak-border__edge--right {
    top: -45%;
    right: 0;
    animation: streak-border-right 2.2s linear infinite 0.55s;
}

.ranking-streak-border--purple .ranking-streak-border__edge--bottom {
    right: -45%;
    bottom: 0;
    animation: streak-border-bottom 2.2s linear infinite 1.1s;
}

.ranking-streak-border--purple .ranking-streak-border__edge--left {
    bottom: -45%;
    left: 0;
    animation: streak-border-left 2.2s linear infinite 1.65s;
}

/* ── Fire (streak 3): short sparks racing opposite direction ── */
.ranking-streak-border--fire .ranking-streak-border__edge {
    filter:
        drop-shadow(0 0 3px rgba(255, 220, 120, 1))
        drop-shadow(0 0 8px rgba(255, 106, 0, 0.95))
        drop-shadow(0 0 14px rgba(255, 60, 0, 0.7));
}

.ranking-streak-border--fire .ranking-streak-border__edge--top,
.ranking-streak-border--fire .ranking-streak-border__edge--bottom {
    width: 28%;
    height: 2.5px;
    background: linear-gradient(
        90deg,
        transparent,
        #ff6a00,
        #ffd166,
        #ff3b00,
        transparent
    );
}

.ranking-streak-border--fire .ranking-streak-border__edge--left,
.ranking-streak-border--fire .ranking-streak-border__edge--right {
    width: 2.5px;
    height: 28%;
    background: linear-gradient(
        180deg,
        transparent,
        #ff6a00,
        #ffd166,
        #ff3b00,
        transparent
    );
}

.ranking-streak-border--fire .ranking-streak-border__edge--top {
    top: 0;
    right: -28%;
    animation: streak-border-top-reverse 1.35s linear infinite;
}

.ranking-streak-border--fire .ranking-streak-border__edge--right {
    bottom: -28%;
    right: 0;
    animation: streak-border-right-reverse 1.35s linear infinite 0.34s;
}

.ranking-streak-border--fire .ranking-streak-border__edge--bottom {
    left: -28%;
    bottom: 0;
    animation: streak-border-bottom-reverse 1.35s linear infinite 0.68s;
}

.ranking-streak-border--fire .ranking-streak-border__edge--left {
    top: -28%;
    left: 0;
    animation: streak-border-left-reverse 1.35s linear infinite 1.02s;
}

/* ── Ice (streak 4): slow dual-edge shimmer meeting from corners ── */
.ranking-streak-border--ice .ranking-streak-border__edge {
    filter:
        drop-shadow(0 0 3px rgba(224, 242, 254, 1))
        drop-shadow(0 0 8px rgba(56, 189, 248, 0.95))
        drop-shadow(0 0 14px rgba(14, 165, 233, 0.65));
}

.ranking-streak-border--ice .ranking-streak-border__edge--top,
.ranking-streak-border--ice .ranking-streak-border__edge--bottom {
    width: 55%;
    height: 2px;
    background: linear-gradient(
        90deg,
        transparent 0%,
        rgba(125, 211, 252, 0.15) 20%,
        #e0f2fe 48%,
        #38bdf8 52%,
        rgba(125, 211, 252, 0.15) 80%,
        transparent 100%
    );
    opacity: 0.85;
}

.ranking-streak-border--ice .ranking-streak-border__edge--left,
.ranking-streak-border--ice .ranking-streak-border__edge--right {
    width: 2px;
    height: 55%;
    background: linear-gradient(
        180deg,
        transparent 0%,
        rgba(125, 211, 252, 0.15) 20%,
        #e0f2fe 48%,
        #38bdf8 52%,
        rgba(125, 211, 252, 0.15) 80%,
        transparent 100%
    );
    opacity: 0.85;
}

.ranking-streak-border--ice .ranking-streak-border__edge--top {
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    animation: streak-border-ice-horizontal 2.8s ease-in-out infinite;
}

.ranking-streak-border--ice .ranking-streak-border__edge--bottom {
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    animation: streak-border-ice-horizontal 2.8s ease-in-out infinite 1.4s;
}

.ranking-streak-border--ice .ranking-streak-border__edge--left {
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    animation: streak-border-ice-vertical 2.8s ease-in-out infinite 0.7s;
}

.ranking-streak-border--ice .ranking-streak-border__edge--right {
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    animation: streak-border-ice-vertical 2.8s ease-in-out infinite 2.1s;
}

/* ── Skulls (streak 6) ── */
.ranking-streak-border--skulls .ranking-streak-border__edge {
    filter:
        drop-shadow(0 0 4px rgba(255, 255, 255, 0.95))
        drop-shadow(0 0 8px rgba(148, 163, 184, 1))
        drop-shadow(0 0 12px rgba(100, 116, 139, 0.9));
}

.ranking-streak-border--skulls .ranking-streak-border__edge--top,
.ranking-streak-border--skulls .ranking-streak-border__edge--bottom {
    width: 42%;
    height: 3px;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(148, 163, 184, 0.3),
        #ffffff,
        #94a3b8,
        transparent
    );
}

.ranking-streak-border--skulls .ranking-streak-border__edge--left,
.ranking-streak-border--skulls .ranking-streak-border__edge--right {
    width: 3px;
    height: 42%;
    background: linear-gradient(
        180deg,
        transparent,
        rgba(148, 163, 184, 0.3),
        #ffffff,
        #94a3b8,
        transparent
    );
}

.ranking-streak-border--skulls .ranking-streak-border__edge--top {
    top: 0;
    left: -42%;
    animation: streak-border-top 2.4s linear infinite;
}

.ranking-streak-border--skulls .ranking-streak-border__edge--right {
    top: -42%;
    right: 0;
    animation: streak-border-right 2.4s linear infinite 0.6s;
}

.ranking-streak-border--skulls .ranking-streak-border__edge--bottom {
    right: -42%;
    bottom: 0;
    animation: streak-border-bottom 2.4s linear infinite 1.2s;
}

.ranking-streak-border--skulls .ranking-streak-border__edge--left {
    bottom: -42%;
    left: 0;
    animation: streak-border-left 2.4s linear infinite 1.8s;
}

/* ── Dragon (streak 7+) ── */
.ranking-streak-border--dragon .ranking-streak-border__edge {
    filter:
        drop-shadow(0 0 4px rgba(254, 202, 202, 1))
        drop-shadow(0 0 8px rgba(239, 68, 68, 1))
        drop-shadow(0 0 12px rgba(185, 28, 28, 0.95));
}

.ranking-streak-border--dragon .ranking-streak-border__edge--top,
.ranking-streak-border--dragon .ranking-streak-border__edge--bottom {
    width: 40%;
    height: 3px;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(239, 68, 68, 0.35),
        #ffffff,
        #ef4444,
        transparent
    );
}

.ranking-streak-border--dragon .ranking-streak-border__edge--left,
.ranking-streak-border--dragon .ranking-streak-border__edge--right {
    width: 3px;
    height: 40%;
    background: linear-gradient(
        180deg,
        transparent,
        rgba(239, 68, 68, 0.35),
        #ffffff,
        #ef4444,
        transparent
    );
}

.ranking-streak-border--dragon .ranking-streak-border__edge--top {
    top: 0;
    left: -40%;
    animation: streak-border-top 2s linear infinite;
}

.ranking-streak-border--dragon .ranking-streak-border__edge--right {
    top: -40%;
    right: 0;
    animation: streak-border-right 2s linear infinite 0.5s;
}

.ranking-streak-border--dragon .ranking-streak-border__edge--bottom {
    right: -40%;
    bottom: 0;
    animation: streak-border-bottom 2s linear infinite 1s;
}

.ranking-streak-border--dragon .ranking-streak-border__edge--left {
    bottom: -40%;
    left: 0;
    animation: streak-border-left 2s linear infinite 1.5s;
}

/* Shared chase keyframes (purple / thunder style) */
@keyframes streak-border-top {
    0% { left: -45%; opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { left: 100%; opacity: 0; }
}

@keyframes streak-border-right {
    0% { top: -45%; opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { top: 100%; opacity: 0; }
}

@keyframes streak-border-bottom {
    0% { right: -45%; opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { right: 100%; opacity: 0; }
}

@keyframes streak-border-left {
    0% { bottom: -45%; opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { bottom: 100%; opacity: 0; }
}

/* Fire: reverse chase, shorter & faster */
@keyframes streak-border-top-reverse {
    0% { right: -28%; opacity: 0; }
    12% { opacity: 1; }
    88% { opacity: 1; }
    100% { right: 100%; opacity: 0; }
}

@keyframes streak-border-right-reverse {
    0% { bottom: -28%; opacity: 0; }
    12% { opacity: 1; }
    88% { opacity: 1; }
    100% { bottom: 100%; opacity: 0; }
}

@keyframes streak-border-bottom-reverse {
    0% { left: -28%; opacity: 0; }
    12% { opacity: 1; }
    88% { opacity: 1; }
    100% { left: 100%; opacity: 0; }
}

@keyframes streak-border-left-reverse {
    0% { top: -28%; opacity: 0; }
    12% { opacity: 1; }
    88% { opacity: 1; }
    100% { top: 100%; opacity: 0; }
}

/* Ice: breathe / expand from center of each edge */
@keyframes streak-border-ice-horizontal {
    0%,
    100% {
        width: 18%;
        opacity: 0.25;
        filter:
            drop-shadow(0 0 2px rgba(224, 242, 254, 0.6))
            drop-shadow(0 0 6px rgba(56, 189, 248, 0.4));
    }

    45%,
    55% {
        width: 72%;
        opacity: 1;
        filter:
            drop-shadow(0 0 4px rgba(224, 242, 254, 1))
            drop-shadow(0 0 10px rgba(56, 189, 248, 1))
            drop-shadow(0 0 16px rgba(14, 165, 233, 0.8));
    }
}

@keyframes streak-border-ice-vertical {
    0%,
    100% {
        height: 18%;
        opacity: 0.25;
        filter:
            drop-shadow(0 0 2px rgba(224, 242, 254, 0.6))
            drop-shadow(0 0 6px rgba(56, 189, 248, 0.4));
    }

    45%,
    55% {
        height: 72%;
        opacity: 1;
        filter:
            drop-shadow(0 0 4px rgba(224, 242, 254, 1))
            drop-shadow(0 0 10px rgba(56, 189, 248, 1))
            drop-shadow(0 0 16px rgba(14, 165, 233, 0.8));
    }
}

@media (prefers-reduced-motion: reduce) {
    .ranking-streak-border__edge {
        animation: none !important;
        opacity: 0.7;
    }

    .ranking-streak-border--purple .ranking-streak-border__edge--top,
    .ranking-streak-border--fire .ranking-streak-border__edge--top,
    .ranking-streak-border--skulls .ranking-streak-border__edge--top,
    .ranking-streak-border--dragon .ranking-streak-border__edge--top {
        left: 27.5%;
        right: auto;
    }

    .ranking-streak-border--purple .ranking-streak-border__edge--right,
    .ranking-streak-border--fire .ranking-streak-border__edge--right,
    .ranking-streak-border--skulls .ranking-streak-border__edge--right,
    .ranking-streak-border--dragon .ranking-streak-border__edge--right {
        top: 27.5%;
        bottom: auto;
    }

    .ranking-streak-border--purple .ranking-streak-border__edge--bottom,
    .ranking-streak-border--fire .ranking-streak-border__edge--bottom,
    .ranking-streak-border--skulls .ranking-streak-border__edge--bottom,
    .ranking-streak-border--dragon .ranking-streak-border__edge--bottom {
        right: 27.5%;
        left: auto;
    }

    .ranking-streak-border--purple .ranking-streak-border__edge--left,
    .ranking-streak-border--fire .ranking-streak-border__edge--left,
    .ranking-streak-border--skulls .ranking-streak-border__edge--left,
    .ranking-streak-border--dragon .ranking-streak-border__edge--left {
        bottom: 27.5%;
        top: auto;
    }

    .ranking-streak-border--ice .ranking-streak-border__edge--top,
    .ranking-streak-border--ice .ranking-streak-border__edge--bottom {
        width: 55%;
    }

    .ranking-streak-border--ice .ranking-streak-border__edge--left,
    .ranking-streak-border--ice .ranking-streak-border__edge--right {
        height: 55%;
    }
}
</style>
