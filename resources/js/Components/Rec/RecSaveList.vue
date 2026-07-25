<script setup>
import RecSaveCard from './RecSaveCard.vue';

defineProps({
    saves: { type: Array, default: () => [] },
    pending: { type: Object, default: () => ({}) },
    v2: Boolean,
});
</script>

<template>
    <section class="space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide px-1">Clips salvos</h2>
        <p v-if="!saves.length" class="text-sm text-gray-500 px-1">Nenhum clip salvo ainda.</p>
        <RecSaveCard
            v-for="save in saves"
            :key="save.uuid"
            :save="save"
            :pending="pending[save.uuid]"
            :v2="v2"
        />
    </section>
</template>
