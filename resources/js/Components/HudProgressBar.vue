<script setup>
import { computed } from 'vue';

const props = defineProps({
    /** 0 a 100, ou null quando não há dados */
    value: {
        type: Number,
        default: null,
    },
    /** verde/amarelo/vermelho automático pelo valor, ou uma cor fixa */
    tone: {
        type: String,
        default: 'auto',
    },
});

const resolvedTone = computed(() => {
    if (props.tone !== 'auto') return props.tone;
    if (props.value == null) return 'muted';
    if (props.value >= 66) return 'success';
    if (props.value >= 33) return 'warning';
    return 'danger';
});

const width = computed(() => `${Math.min(100, Math.max(0, props.value ?? 0))}%`);
</script>

<template>
    <div class="hud-bar" :class="`hud-bar--${resolvedTone}`">
        <div class="hud-bar__fill" :style="{ width }" />
    </div>
</template>

<style scoped>
.hud-bar {
    --bar: #64748b;
    --bar-rgb: 100, 116, 139;

    position: relative;
    height: 10px;
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.85);
}

.hud-bar--success {
    --bar: #22c55e;
    --bar-rgb: 34, 197, 94;
}

.hud-bar--warning {
    --bar: #eab308;
    --bar-rgb: 234, 179, 8;
}

.hud-bar--danger {
    --bar: #ef4444;
    --bar-rgb: 239, 68, 68;
}

.hud-bar--muted {
    --bar: #475569;
    --bar-rgb: 71, 85, 105;
}

.hud-bar__fill {
    height: 100%;
    border-radius: 999px;
    background:
        repeating-linear-gradient(
            115deg,
            rgba(255, 255, 255, 0.22) 0 3px,
            transparent 3px 8px
        ),
        linear-gradient(90deg, rgba(var(--bar-rgb), 0.75), var(--bar));
    box-shadow: 0 0 12px rgba(var(--bar-rgb), 0.55);
    transition: width 260ms ease;
}
</style>
