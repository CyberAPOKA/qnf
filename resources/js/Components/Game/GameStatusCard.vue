<script setup>
defineProps({
    statusLabel: String,
    status: String,
    playersCount: Number,
    round: {
        type: Number,
        default: null,
    },
});
</script>

<template>
    <div class="rounded-xl bg-white p-2 lg:p-4 shadow text-center">
        <p v-if="round" class="text-3xl font-bold uppercase tracking-wide text-gray-900 round-title">
            <i class="fa-solid fa-gem text-2xl round-ice"></i>
            Rodada {{ round }}
            <i class="fa-regular fa-gem text-2xl round-ice"></i>
        </p>
        <p v-if="!['scheduled', 'drafted', 'done'].includes(status)" class="mt-2 text-sm text-gray-700">
            Inscritos: <span class="font-semibold">{{ playersCount }}/15</span>
        </p>
        <slot name="details" v-if="!['drafted', 'done'].includes(status)" />

        <div class="mt-2 space-y-2" v-if="!['drafted', 'done'].includes(status)">
            <slot name="actions" />
        </div>

        <slot name="footer" />
    </div>
</template>
<style>
.round-title {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .6rem;
    padding: .35rem 1rem;
    border-radius: 999px;
    isolation: isolate;
    transform: translateZ(0);
}

.round-title::before {
    content: "";
    position: absolute;
    inset: -6px -10px;
    border-radius: 999px;
    background:
        radial-gradient(120px 40px at 12% 120%, rgba(120, 210, 255, .55), transparent 65%),
        radial-gradient(140px 50px at 88% 120%, rgba(80, 180, 255, .45), transparent 70%),
        radial-gradient(260px 80px at 50% 130%, rgba(180, 235, 255, .35), transparent 75%);
    filter: blur(10px);
    opacity: .9;
    z-index: -2;
    animation: qnfGlow 1.2s ease-in-out infinite alternate;
}

.round-text {
    position: relative;
    letter-spacing: .06em;
    text-shadow:
        0 0 10px rgba(120, 210, 255, .55),
        0 0 24px rgba(80, 180, 255, .35);
    animation: qnfPulse .9s ease-in-out infinite alternate;
}

.round-text::before {
    content: "";
    position: absolute;
    left: -10%;
    right: -10%;
    bottom: -8px;
    height: 10px;
    border-radius: 999px;
    background:
        radial-gradient(closest-side, rgba(200, 245, 255, .85), rgba(120, 210, 255, .55), transparent 70%);
    filter: blur(6px);
    opacity: .85;
    z-index: -1;
    animation: qnfHeat 1.1s ease-in-out infinite;
}

.round-ice {
    color: #7dd3fc;
    filter: drop-shadow(0 0 10px rgba(120, 210, 255, .75));
    animation: qnfFlame .55s ease-in-out infinite alternate;
}

.round-ice:first-child {
    animation-delay: .08s;
}

.round-ice:last-child {
    animation-delay: .22s;
}

@keyframes qnfFlame {
    0% {
        transform: translateY(1px) scale(.98) rotate(-6deg);
        filter: drop-shadow(0 0 8px rgba(120, 210, 255, .65));
    }

    100% {
        transform: translateY(-2px) scale(1.08) rotate(6deg);
        filter: drop-shadow(0 0 14px rgba(180, 235, 255, .85));
    }
}

@keyframes qnfGlow {
    0% {
        transform: scale(.98);
        opacity: .75;
    }

    100% {
        transform: scale(1.03);
        opacity: 1;
    }
}

@keyframes qnfPulse {
    0% {
        transform: translateY(0);
    }

    100% {
        transform: translateY(-1px);
    }
}

@keyframes qnfHeat {

    0%,
    100% {
        transform: translateY(0) scaleX(1);
        opacity: .75;
    }

    50% {
        transform: translateY(-2px) scaleX(1.08);
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {

    .round-title::before,
    .round-text,
    .round-text::before,
    .round-ice {
        animation: none !important;
    }
}
</style>
