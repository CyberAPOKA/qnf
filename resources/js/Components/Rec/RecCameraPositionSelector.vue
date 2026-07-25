<script setup>
defineProps({
    selected: String,
    taken: { type: Object, required: true },
    disabled: Boolean,
});

defineEmits(['select']);

const groups = [
    [
        { tag: 'B1', label: 'Lateral B · Esquerda' },
        { tag: 'B2', label: 'Lateral B · Direita' },
    ],
    [
        { tag: 'A1', label: 'Lateral A · Esquerda' },
        { tag: 'A2', label: 'Lateral A · Direita' },
    ],
];
</script>

<template>
    <section class="rounded-2xl bg-white shadow overflow-hidden">
        <h2 class="px-4 pt-4 pb-2 text-lg text-center font-semibold text-gray-900 uppercase tracking-wide">
            Ângulo da câmera
        </h2>
        <div class="px-3 pb-4 space-y-2">
            <template v-for="(angles, index) in groups" :key="index">
                <div class="grid grid-cols-2 gap-2">
                    <button
                        v-for="angle in angles"
                        :key="angle.tag"
                        type="button"
                        class="rounded-xl border-2 px-3 py-3 text-left transition active:scale-[0.98]"
                        :class="selected === angle.tag
                            ? 'border-red-600 bg-red-50'
                            : taken.has(angle.tag)
                                ? 'border-gray-200 bg-white opacity-45'
                                : 'border-gray-200 bg-white hover:border-red-300'"
                        :disabled="disabled"
                        @click="$emit('select', angle.tag)"
                    >
                        <span class="inline-flex items-center gap-2">
                            <span class="rounded-md bg-red-600 text-white text-xs font-bold px-2 py-0.5">
                                {{ angle.tag }}
                            </span>
                            <span v-if="taken.has(angle.tag)" class="text-[10px] uppercase font-semibold text-amber-600">
                                em uso
                            </span>
                        </span>
                        <span class="block text-xs text-gray-600 mt-1">{{ angle.label }}</span>
                    </button>
                </div>
                <img
                    v-if="index === 0"
                    src="/assets/rec/court_positions.png"
                    alt="Mapa de ângulos da quadra"
                    class="w-full h-auto block rounded-xl bg-[#1e4fd6]"
                />
            </template>
        </div>
    </section>
</template>
