<template>
    <div class="flex justify-center">
        <h2 class="qnf-title font-semibold text-lg text-gray-800 leading-tight text-center">
            <i class="fa-solid fa-fire qnf-fire"></i>
            <span class="qnf-text">QUINTA NOBRE FUTSAL 2026</span>
            <i class="fa-solid fa-fire qnf-fire"></i>
        </h2>
    </div>
</template>

<style scoped>
.qnf-title {
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

.qnf-title::before {
    content: "";
    position: absolute;
    inset: -6px -10px;
    border-radius: 999px;
    background:
        radial-gradient(120px 40px at 12% 120%, rgba(255, 120, 0, .55), transparent 65%),
        radial-gradient(140px 50px at 88% 120%, rgba(255, 0, 0, .45), transparent 70%),
        radial-gradient(260px 80px at 50% 130%, rgba(255, 200, 0, .35), transparent 75%);
    filter: blur(10px);
    opacity: .9;
    z-index: -2;
    animation: qnfGlow 1.2s ease-in-out infinite alternate;
}

.qnf-text {
    position: relative;
    letter-spacing: .06em;
    text-shadow:
        0 0 10px rgba(255, 120, 0, .55),
        0 0 24px rgba(255, 0, 0, .35);
    animation: qnfPulse .9s ease-in-out infinite alternate;
}

.qnf-text::before {
    content: "";
    position: absolute;
    left: -10%;
    right: -10%;
    bottom: -8px;
    height: 10px;
    border-radius: 999px;
    background:
        radial-gradient(closest-side, rgba(255, 200, 0, .85), rgba(255, 80, 0, .55), transparent 70%);
    filter: blur(6px);
    opacity: .85;
    z-index: -1;
    animation: qnfHeat 1.1s ease-in-out infinite;
}

.qnf-fire {
    color: #ff3b30;
    filter: drop-shadow(0 0 10px rgba(255, 90, 0, .75));
    animation: qnfFlame .55s ease-in-out infinite alternate;
}

.qnf-fire:first-child {
    animation-delay: .08s;
}

.qnf-fire:last-child {
    animation-delay: .22s;
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

    .qnf-title::before,
    .qnf-text,
    .qnf-text::before,
    .qnf-fire {
        animation: none !important;
    }
}
</style>
