<script setup>
import { ref } from 'vue';

defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    placeholder: {
        type: String,
        default: '',
    },
    icon: {
        type: String,
        default: '',
    },
    clearable: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const inputRef = ref(null);

const clear = () => {
    emit('update:modelValue', '');
    inputRef.value?.focus();
};

defineExpose({ focus: () => inputRef.value?.focus() });
</script>

<template>
    <label class="hud-input" :class="{ 'hud-input--disabled': disabled }">
        <i v-if="icon" class="hud-input__icon" :class="icon" aria-hidden="true" />

        <input
            ref="inputRef"
            class="hud-input__field"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            @input="emit('update:modelValue', $event.target.value)"
        >

        <button
            v-if="clearable && String(modelValue || '').length"
            type="button"
            class="hud-input__clear"
            aria-label="Limpar"
            @click="clear"
        >
            <i class="fa-solid fa-xmark" aria-hidden="true" />
        </button>
    </label>
</template>

<style scoped>
.hud-input {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 12px 16px;
    border: 1px solid rgba(56, 189, 248, 0.35);
    border-radius: 12px;
    background: linear-gradient(180deg, rgba(11, 19, 36, 0.92), rgba(6, 11, 22, 0.92));
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.04),
        0 6px 18px rgba(0, 0, 0, 0.35);
    cursor: text;
    transition: border-color 160ms ease, box-shadow 160ms ease;
}

.hud-input:focus-within {
    border-color: rgba(56, 189, 248, 0.75);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.05),
        0 0 16px rgba(56, 189, 248, 0.25);
}

.hud-input--disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.hud-input__icon {
    flex: 0 0 auto;
    color: #7dd3fc;
    font-size: 0.95rem;
}

.hud-input__field {
    flex: 1 1 auto;
    min-width: 0;
    padding: 0;
    border: 0;
    background: transparent;
    color: #e2e8f0;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 1rem;
    font-weight: 600;
}

.hud-input__field::placeholder {
    color: #64748b;
    font-weight: 500;
}

.hud-input__field:focus {
    outline: none;
    border: 0;
    box-shadow: none;
}

.hud-input__field::-webkit-search-cancel-button {
    display: none;
}

.hud-input__clear {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.16);
    color: #cbd5e1;
    font-size: 0.7rem;
    transition: background 140ms ease, color 140ms ease;
}

.hud-input__clear:hover {
    background: rgba(56, 189, 248, 0.25);
    color: #e0f2fe;
}
</style>
