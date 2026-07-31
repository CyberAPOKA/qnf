<script setup>
defineProps({
    modelValue: {
        type: String,
        required: true,
    },
    tabs: {
        type: Array,
        required: true,
    },
})

const emit = defineEmits(['update:modelValue'])
</script>

<template>
    <nav class="tab-nav" aria-label="Navegação principal">
        <div class="tab-nav__rail">
            <button
                v-for="tab in tabs"
                :key="tab.value"
                type="button"
                class="tab-nav__item"
                :class="{ 'tab-nav__item--active': modelValue === tab.value }"
                :aria-current="modelValue === tab.value ? 'page' : undefined"
                @click="emit('update:modelValue', tab.value)"
            >
                <span class="tab-nav__face">
                    <i :class="[tab.icon, 'tab-nav__icon']" aria-hidden="true" />
                    <span class="tab-nav__label">{{ tab.label }}</span>
                </span>
                <span class="tab-nav__glow" aria-hidden="true" />
                <span class="tab-nav__notch" aria-hidden="true" />
            </button>
        </div>
    </nav>
</template>

<style scoped>
.tab-nav {
    --tab-purple: #a855f7;
    --tab-purple-bright: #c084fc;
    --tab-purple-deep: #6d28d9;
    --tab-purple-dim: rgba(168, 85, 247, 0.35);
    --tab-surface: rgba(12, 8, 24, 0.92);
    --tab-surface-active-top: rgba(126, 34, 206, 0.92);
    --tab-surface-active-bot: rgba(46, 16, 84, 0.98);

    position: fixed;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 40;
    padding: 0 4px env(safe-area-inset-bottom, 0px);
    pointer-events: none;
}

.tab-nav__rail {
    pointer-events: auto;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    gap: 4px;
    width: 100%;
    max-width: 42rem;
    margin: 0 auto;
    padding: 10px 6px 6px;
    background:
        linear-gradient(
            180deg,
            rgba(10, 6, 22, 0.2) 0%,
            rgba(10, 6, 22, 0.88) 45%,
            rgba(7, 4, 16, 0.96) 100%
        );
    border: 1px solid rgba(168, 85, 247, 0.28);
    clip-path: polygon(
        12px 0,
        calc(100% - 12px) 0,
        100% 12px,
        100% 100%,
        0 100%,
        0 12px
    );
    box-shadow:
        0 -8px 28px rgba(88, 28, 135, 0.35),
        inset 0 1px 0 rgba(216, 180, 254, 0.12);
    backdrop-filter: blur(10px);
}

.tab-nav__item {
    position: relative;
    display: flex;
    flex: 1 1 0;
    align-items: center;
    justify-content: center;
    min-width: 0;
    height: 52px;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
    transition: transform 180ms ease, filter 180ms ease;
}

.tab-nav__face {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    width: 100%;
    height: 100%;
    color: #c4b5fd;
    background:
        linear-gradient(
            165deg,
            rgba(48, 24, 80, 0.55) 0%,
            var(--tab-surface) 55%,
            rgba(8, 5, 18, 0.95) 100%
        );
    clip-path: polygon(
        10px 0,
        calc(100% - 10px) 0,
        100% 12px,
        100% calc(100% - 10px),
        calc(100% - 10px) 100%,
        10px 100%,
        0 calc(100% - 10px),
        0 12px
    );
    box-shadow:
        inset 0 0 0 1px rgba(168, 85, 247, 0.35),
        inset 0 0 18px rgba(88, 28, 135, 0.25);
    transition: color 180ms ease, background 180ms ease, box-shadow 180ms ease, filter 180ms ease;
}

.tab-nav__face::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    background: linear-gradient(
        120deg,
        transparent 0 28%,
        rgba(192, 132, 252, 0.12) 28% 28.6%,
        transparent 28.6% 62%,
        rgba(168, 85, 247, 0.1) 62% 62.6%,
        transparent 62.6%
    );
    clip-path: inherit;
    pointer-events: none;
}

.tab-nav__icon {
    font-size: 1rem;
    line-height: 1;
    filter: drop-shadow(0 0 6px rgba(168, 85, 247, 0.35));
    transition: transform 180ms ease, filter 180ms ease, color 180ms ease;
}

.tab-nav__label {
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    line-height: 1;
}

.tab-nav__glow,
.tab-nav__notch {
    display: none;
}

.tab-nav__item:hover .tab-nav__face {
    color: #e9d5ff;
    box-shadow:
        inset 0 0 0 1px rgba(192, 132, 252, 0.55),
        inset 0 0 22px rgba(126, 34, 206, 0.35);
}

.tab-nav__item:hover .tab-nav__icon {
    transform: translateY(-1px);
    filter: drop-shadow(0 0 8px rgba(192, 132, 252, 0.7));
}

/* ── Active tab ── */
.tab-nav__item--active {
    z-index: 2;
    flex: 1.35 1 0;
    height: 64px;
    margin-bottom: 2px;
    transform: translateY(-6px);
}

.tab-nav__item--active .tab-nav__face {
    color: #f5e9ff;
    background:
        linear-gradient(
            180deg,
            rgba(168, 85, 247, 0.95) 0%,
            var(--tab-surface-active-top) 38%,
            var(--tab-surface-active-bot) 100%
        );
    clip-path: polygon(
        14px 0,
        calc(100% - 14px) 0,
        100% 16px,
        100% calc(100% - 12px),
        calc(100% - 12px) 100%,
        12px 100%,
        0 calc(100% - 12px),
        0 16px
    );
    box-shadow:
        inset 0 0 0 1.5px rgba(233, 213, 255, 0.75),
        inset 0 -18px 28px rgba(88, 28, 135, 0.55),
        inset 0 10px 20px rgba(216, 180, 254, 0.2),
        0 0 18px rgba(168, 85, 247, 0.55),
        0 0 34px rgba(126, 34, 206, 0.35);
    filter: drop-shadow(0 0 10px rgba(168, 85, 247, 0.55));
}

.tab-nav__item--active .tab-nav__icon {
    font-size: 1.15rem;
    color: #faf5ff;
    filter:
        drop-shadow(0 0 6px rgba(255, 255, 255, 0.75))
        drop-shadow(0 0 12px rgba(192, 132, 252, 0.95));
}

.tab-nav__item--active .tab-nav__label {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-shadow: 0 0 10px rgba(233, 213, 255, 0.65);
}

.tab-nav__item--active .tab-nav__glow {
    display: block;
    position: absolute;
    left: 50%;
    bottom: -2px;
    z-index: 1;
    width: 70%;
    height: 28px;
    border-radius: 999px;
    background: radial-gradient(ellipse at center, rgba(192, 132, 252, 0.7), transparent 70%);
    transform: translateX(-50%);
    filter: blur(8px);
    pointer-events: none;
}

.tab-nav__item--active .tab-nav__notch {
    display: block;
    position: absolute;
    left: 50%;
    bottom: -5px;
    z-index: 3;
    width: 10px;
    height: 10px;
    background: linear-gradient(135deg, #e9d5ff 0%, var(--tab-purple) 55%, var(--tab-purple-deep) 100%);
    border: 1px solid rgba(245, 233, 255, 0.85);
    transform: translateX(-50%) rotate(45deg);
    box-shadow:
        0 0 8px rgba(192, 132, 252, 0.9),
        0 0 16px rgba(168, 85, 247, 0.65);
    pointer-events: none;
}

@media (min-width: 768px) {
    .tab-nav {
        position: static;
        z-index: auto;
        padding: 0;
        margin-bottom: 0.25rem;
    }

    .tab-nav__rail {
        max-width: none;
        padding: 12px 10px 10px;
        clip-path: polygon(
            14px 0,
            calc(100% - 14px) 0,
            100% 14px,
            100% calc(100% - 14px),
            calc(100% - 14px) 100%,
            14px 100%,
            0 calc(100% - 14px),
            0 14px
        );
    }

    .tab-nav__item {
        height: 56px;
    }

    .tab-nav__item--active {
        height: 68px;
    }

    .tab-nav__label {
        font-size: 0.78rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .tab-nav__item,
    .tab-nav__face,
    .tab-nav__icon {
        transition: none;
    }
}
</style>
