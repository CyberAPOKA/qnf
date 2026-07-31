<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: {
        type: [String, Number, Boolean, Object],
        default: null,
    },
    options: {
        type: Array,
        default: () => [],
    },
    optionLabel: {
        type: [String, Function],
        default: null,
    },
    optionValue: {
        type: [String, Function],
        default: null,
    },
    placeholder: {
        type: String,
        default: 'Selecionar',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    /** `title` = destaque tipo HUD, `field` = campo de formulário */
    variant: {
        type: String,
        default: 'title',
    },
    icon: {
        type: String,
        default: '',
    },
    block: {
        type: Boolean,
        default: false,
    },
    /** número/px, ou null para acompanhar a largura do gatilho no variant field */
    menuWidth: {
        type: [Number, String],
        default: null,
    },
    maxHeight: {
        type: [Number, String],
        default: 260,
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const triggerRef = ref(null);
const menuStyle = ref({});

const isField = computed(() => props.variant === 'field');

const canOpen = computed(() => !props.disabled && props.options.length > 0);

const resolveOptionValue = (option) => {
    if (props.optionValue) {
        return typeof props.optionValue === 'function'
            ? props.optionValue(option)
            : option?.[props.optionValue];
    }

    if (option !== null && typeof option === 'object' && 'value' in option) {
        return option.value;
    }

    return option;
};

const resolveOptionLabel = (option) => {
    if (props.optionLabel) {
        return typeof props.optionLabel === 'function'
            ? props.optionLabel(option)
            : option?.[props.optionLabel];
    }

    if (option !== null && typeof option === 'object' && 'label' in option) {
        return option.label;
    }

    return option;
};

const selectedOption = computed(
    () => props.options.find((option) => resolveOptionValue(option) === props.modelValue) ?? null,
);

const displayLabel = computed(() => {
    if (selectedOption.value == null) return props.placeholder;
    return resolveOptionLabel(selectedOption.value);
});

const toPx = (value, fallback) => {
    if (value == null) return fallback;
    return typeof value === 'number' ? value : Number.parseFloat(value) || fallback;
};

const updateMenuPosition = async () => {
    await nextTick();
    const trigger = triggerRef.value;
    if (!trigger) return;

    const rect = trigger.getBoundingClientRect();
    const defaultWidth = isField.value ? rect.width : 176;
    const width = toPx(props.menuWidth, defaultWidth);
    const maxHeight = toPx(props.maxHeight, 260);

    const gap = 8;
    const viewportPadding = 8;
    const spaceBelow = window.innerHeight - rect.bottom - gap - viewportPadding;
    const spaceAbove = rect.top - gap - viewportPadding;
    const openUp = spaceBelow < Math.min(maxHeight, 160) && spaceAbove > spaceBelow;

    const left = isField.value
        ? Math.max(viewportPadding, Math.min(rect.left, window.innerWidth - width - viewportPadding))
        : Math.max(
            viewportPadding,
            Math.min(
                rect.left + (rect.width / 2) - (width / 2),
                window.innerWidth - width - viewportPadding,
            ),
        );

    menuStyle.value = openUp
        ? {
            left: `${left}px`,
            bottom: `${window.innerHeight - rect.top + gap}px`,
            top: 'auto',
            width: `${width}px`,
            maxHeight: `${Math.min(maxHeight, Math.max(spaceAbove, 120))}px`,
        }
        : {
            left: `${left}px`,
            top: `${rect.bottom + gap}px`,
            bottom: 'auto',
            width: `${width}px`,
            maxHeight: `${Math.min(maxHeight, Math.max(spaceBelow, 120))}px`,
        };
};

const close = () => {
    open.value = false;
};

const toggle = async () => {
    if (!canOpen.value) return;
    open.value = !open.value;
    if (open.value) {
        await updateMenuPosition();
    }
};

const select = (option) => {
    const value = resolveOptionValue(option);
    close();
    if (value === props.modelValue) return;
    emit('update:modelValue', value);
    emit('change', value, option);
};

const onKeydown = (event) => {
    if (event.key === 'Escape') close();
};

const onViewportChange = () => {
    if (open.value) updateMenuPosition();
};

watch(open, (isOpen) => {
    if (isOpen) {
        window.addEventListener('resize', onViewportChange);
        window.addEventListener('scroll', onViewportChange, true);
        window.addEventListener('keydown', onKeydown);
        return;
    }

    window.removeEventListener('resize', onViewportChange);
    window.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('keydown', onKeydown);
});

onMounted(() => {
    if (open.value) updateMenuPosition();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', onViewportChange);
    window.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div
        class="hud-select"
        :class="[
            `hud-select--${variant}`,
            {
                'hud-select--open': open,
                'hud-select--disabled': !canOpen,
                'hud-select--block': block,
            },
        ]"
    >
        <button
            ref="triggerRef"
            type="button"
            class="hud-select__trigger"
            :disabled="!canOpen"
            :aria-expanded="open"
            aria-haspopup="listbox"
            @click="toggle"
        >
            <slot name="trigger" :label="displayLabel" :open="open" :disabled="!canOpen">
                <i v-if="icon" class="hud-select__icon" :class="icon" aria-hidden="true" />
                <span class="hud-select__label">{{ displayLabel }}</span>
                <i
                    v-if="canOpen"
                    class="hud-select__caret"
                    :class="isField ? 'fa-solid fa-chevron-down' : 'fa-solid fa-caret-down'"
                    aria-hidden="true"
                />
            </slot>
        </button>

        <Teleport to="body">
            <div v-if="open" class="hud-select__backdrop" @click="close" />

            <div v-if="open" class="hud-select__menu" role="listbox" :style="menuStyle">
                <button
                    v-for="(option, index) in options"
                    :key="index"
                    type="button"
                    class="hud-select__option"
                    role="option"
                    :aria-selected="resolveOptionValue(option) === modelValue"
                    :class="{ 'hud-select__option--active': resolveOptionValue(option) === modelValue }"
                    @click="select(option)"
                >
                    <slot
                        name="option"
                        :option="option"
                        :label="resolveOptionLabel(option)"
                        :selected="resolveOptionValue(option) === modelValue"
                    >
                        {{ resolveOptionLabel(option) }}
                    </slot>
                </button>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.hud-select {
    position: relative;
    display: inline-flex;
    max-width: 100%;
}

.hud-select--block {
    display: flex;
    width: 100%;
}

.hud-select__trigger {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    max-width: 100%;
    padding: 2px 4px;
    cursor: pointer;
}

.hud-select__trigger:disabled {
    cursor: default;
}

.hud-select--block .hud-select__trigger {
    width: 100%;
}

.hud-select__label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Variante título (HUD) */
.hud-select--title .hud-select__label {
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1.9rem;
    font-style: italic;
    font-weight: 900;
    letter-spacing: 0.04em;
    line-height: 1;
    text-transform: uppercase;
    background: linear-gradient(180deg, #ffffff 0%, #e2e8f0 42%, #8fa3bb 58%, #f8fafc 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    filter: drop-shadow(0 2px 1px rgba(0, 0, 0, 0.65));
}

.hud-select--title .hud-select__caret {
    color: #38bdf8;
    font-size: 0.9rem;
    filter: drop-shadow(0 0 6px rgba(56, 189, 248, 0.8));
    transition: transform 160ms ease;
}

/* Variante campo */
.hud-select--field .hud-select__trigger {
    gap: 12px;
    padding: 12px 16px;
    border: 1px solid rgba(56, 189, 248, 0.35);
    border-radius: 12px;
    background: linear-gradient(180deg, rgba(11, 19, 36, 0.92), rgba(6, 11, 22, 0.92));
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.04),
        0 6px 18px rgba(0, 0, 0, 0.35);
    transition: border-color 160ms ease, box-shadow 160ms ease;
}

.hud-select--field .hud-select__trigger:hover:not(:disabled),
.hud-select--field.hud-select--open .hud-select__trigger {
    border-color: rgba(56, 189, 248, 0.75);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.05),
        0 0 16px rgba(56, 189, 248, 0.25);
}

.hud-select--field .hud-select__icon {
    flex: 0 0 auto;
    color: #7dd3fc;
    font-size: 0.95rem;
}

.hud-select--field .hud-select__label {
    flex: 1 1 auto;
    color: #e2e8f0;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1rem;
    font-weight: 600;
    text-align: left;
}

.hud-select--field .hud-select__caret {
    flex: 0 0 auto;
    color: #94a3b8;
    font-size: 0.8rem;
    transition: transform 160ms ease;
}

.hud-select--open .hud-select__caret {
    transform: rotate(180deg);
}

.hud-select__backdrop {
    position: fixed;
    inset: 0;
    z-index: 90;
}

.hud-select__menu {
    position: fixed;
    z-index: 95;
    overflow-y: auto;
    border: 1px solid rgba(56, 189, 248, 0.35);
    border-radius: 10px;
    background: rgba(5, 10, 22, 0.97);
    box-shadow:
        0 0 0 1px rgba(56, 189, 248, 0.12),
        0 18px 40px rgba(0, 0, 0, 0.6);
}

.hud-select__option {
    display: block;
    width: 100%;
    padding: 10px 14px;
    color: #cbd5e1;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.95rem;
    font-weight: 700;
    text-align: left;
    transition: background 140ms ease, color 140ms ease;
}

.hud-select__option:hover {
    background: rgba(56, 189, 248, 0.14);
    color: #e0f2fe;
}

.hud-select__option--active {
    background: rgba(56, 189, 248, 0.85);
    color: #04121f;
}

@media (min-width: 640px) {
    .hud-select--title .hud-select__label {
        font-size: 3rem;
    }
}
</style>
