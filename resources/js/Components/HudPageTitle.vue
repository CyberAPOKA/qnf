<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
    backHref: {
        type: String,
        default: '',
    },
    emblemIcon: {
        type: String,
        default: 'fa-solid fa-futbol',
    },
});
</script>

<template>
    <header class="hud-title">
        <!-- <Link v-if="backHref" :href="backHref" class="hud-title__back" aria-label="Voltar">
            <i class="fa-solid fa-chevron-left" aria-hidden="true" />
        </Link> -->

        <div class="hud-title__content">
            <h1 class="hud-title__text">{{ title }}</h1>
            <p v-if="subtitle" class="hud-title__subtitle">{{ subtitle }}</p>

            <div class="hud-title__emblem-row" aria-hidden="true">
                <span class="hud-title__rule" />
                <span class="hud-title__emblem"><i :class="emblemIcon" /></span>
                <span class="hud-title__rule" />
            </div>
        </div>
    </header>
</template>

<style scoped>
.hud-title {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 4px 0 0;
}

.hud-title__back {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    color: #e0f2fe;
    font-size: 1rem;
    background: linear-gradient(180deg, rgba(29, 78, 216, 0.55), rgba(8, 14, 28, 0.9));
    box-shadow:
        inset 0 0 0 1.5px rgba(56, 189, 248, 0.7),
        0 0 14px rgba(56, 189, 248, 0.35);
    clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%);
    transition: box-shadow 160ms ease, transform 160ms ease;
}

.hud-title__back:hover {
    transform: translateY(-1px);
    box-shadow:
        inset 0 0 0 1.5px rgba(125, 211, 252, 0.95),
        0 0 22px rgba(56, 189, 248, 0.55);
}

.hud-title__content {
    flex: 1 1 auto;
    min-width: 0;
    text-align: center;
}

.hud-title__text {
    margin: 0;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.9rem;
    font-style: italic;
    font-weight: 900;
    letter-spacing: 0.04em;
    line-height: 1.05;
    text-transform: uppercase;
    background: linear-gradient(180deg, #ffffff 0%, #e2e8f0 42%, #8fa3bb 60%, #f8fafc 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    filter: drop-shadow(0 2px 1px rgba(0, 0, 0, 0.7));
}

.hud-title__subtitle {
    margin: 6px 0 0;
    color: #7dd3fc;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.22em;
    text-transform: uppercase;
}

.hud-title__emblem-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-top: 10px;
}

.hud-title__rule {
    flex: 1 1 auto;
    max-width: 120px;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(56, 189, 248, 0.5), transparent);
}

.hud-title__emblem {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 34px;
    color: #7dd3fc;
    font-size: 0.85rem;
    background: linear-gradient(180deg, rgba(56, 189, 248, 0.28), rgba(8, 14, 28, 0.9));
    box-shadow: inset 0 0 0 1px rgba(56, 189, 248, 0.6);
    clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%);
    filter: drop-shadow(0 0 10px rgba(56, 189, 248, 0.4));
}

@media (min-width: 768px) {
    .hud-title__text {
        font-size: 2.6rem;
    }

    .hud-title__rule {
        max-width: 220px;
    }
}
</style>
