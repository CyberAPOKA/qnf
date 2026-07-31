<script setup>
import MultiSelect from 'primevue/multiselect';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

defineProps({
    modelValue: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        required: true,
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Selecione',
    },
    createLabel: {
        type: String,
        default: '',
    },
    submitLabel: {
        type: String,
        default: 'Adicionar selecionados',
    },
    badgeClass: {
        type: String,
        default: 'bg-gray-100 text-gray-700',
    },
    submitClass: {
        type: String,
        default: '',
    },
    processing: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:modelValue', 'create', 'submit']);
</script>

<template>
    <div class="rounded-xl bg-white p-2 shadow lg:p-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900">{{ title }}</h3>
            <SecondaryButton v-if="createLabel" class="text-xs" @click="$emit('create')">
                {{ createLabel }}
            </SecondaryButton>
        </div>

        <div class="mt-3 space-y-3">
            <MultiSelect :model-value="modelValue" :options="options" option-label="name" :placeholder="placeholder"
                filter :max-selected-labels="3" class="w-full"
                @update:model-value="$emit('update:modelValue', $event)">
                <template #option="{ option }">
                    <div class="flex items-center gap-2">
                        <span>{{ option.name }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass">
                            {{ option.position_label }}
                        </span>
                    </div>
                </template>
            </MultiSelect>

            <PrimaryButton class="w-full justify-center py-3 text-base" :class="submitClass"
                :disabled="processing || !modelValue.length" @click="$emit('submit')">
                {{ submitLabel }}
            </PrimaryButton>
        </div>
    </div>
</template>
