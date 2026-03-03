<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    gameId: Number,
    teams: Object,
});

const form = useForm({
    scores: {
        green: props.teams?.green?.score ?? '',
        yellow: props.teams?.yellow?.score ?? '',
        blue: props.teams?.blue?.score ?? '',
    },
});

watch(() => props.teams, (teams) => {
    form.scores.green = teams?.green?.score ?? '';
    form.scores.yellow = teams?.yellow?.score ?? '';
    form.scores.blue = teams?.blue?.score ?? '';
}, { deep: true });

const submit = () => {
    form.post(route('games.save-scores', props.gameId), {
        preserveScroll: true,
        preserveState: false,
    });
};

const teamFields = [
    { key: 'green', label: 'Time Verde', emoji: '🟢', bg: 'bg-green-50', border: 'border-green-300', focus: 'focus:ring-green-500' },
    { key: 'yellow', label: 'Time Amarelo', emoji: '🟡', bg: 'bg-yellow-50', border: 'border-yellow-300', focus: 'focus:ring-yellow-500' },
    { key: 'blue', label: 'Time Azul', emoji: '🔵', bg: 'bg-blue-50', border: 'border-blue-300', focus: 'focus:ring-blue-500' },
];
</script>

<template>
    <div class="rounded-xl bg-white p-2 lg:p-4 shadow">
        <h3 class="mb-3 text-base font-semibold text-gray-900">Registrar Placar</h3>

        <div class="space-y-3">
            <div v-for="field in teamFields" :key="field.key" class="flex items-center gap-3 rounded-lg p-3"
                :class="field.bg">
                <span class="text-lg">{{ field.emoji }}</span>
                <span class="flex-1 text-sm font-medium text-gray-700">{{ field.label }}</span>
                <input v-model.number="form.scores[field.key]" type="number" min="0" max="99"
                    class="w-16 rounded-md border px-2 py-1.5 text-center text-lg font-bold focus:outline-none focus:ring-2"
                    :class="[field.border, field.focus]" />
            </div>
        </div>

        <div v-if="form.errors.scores" class="mt-2 text-sm text-red-600">{{ form.errors.scores }}</div>

        <PrimaryButton class="mt-4 w-full justify-center py-3 text-base" :disabled="form.processing" @click="submit">
            Salvar Placar
        </PrimaryButton>
    </div>
</template>
