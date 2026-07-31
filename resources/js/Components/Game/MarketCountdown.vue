<script setup>
defineProps({
    label: {
        type: String,
        default: 'Mercado em',
    },
    value: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="countdown">
        <p class="countdown__label">{{ label }}</p>
        <p v-if="value" class="countdown__value">{{ value }}</p>
    </div>
</template>

<style scoped>
.countdown {
    text-align: center;
}

.countdown__label {
    margin: 0;
    color: #e2e8f0;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.34em;
    text-indent: 0.34em;
    text-transform: uppercase;
    text-shadow: 0 0 12px rgba(148, 163, 184, 0.4);
}

.countdown__value {
    margin: 6px 0 0;
    color: #7dd3fc;
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 2.1rem;
    font-weight: 800;
    font-style: italic;
    letter-spacing: 0.02em;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    text-shadow:
        0 0 12px rgba(56, 189, 248, 0.85),
        0 0 34px rgba(37, 99, 235, 0.6);
}

@media (min-width: 640px) {
    .countdown__label {
        font-size: 1.25rem;
    }

    .countdown__value {
        font-size: 3.2rem;
        margin-top: 10px;
    }
}
</style>
