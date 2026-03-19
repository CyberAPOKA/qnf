<script setup>
import { computed } from 'vue';

const props = defineProps({
    prediction: {
        type: Object,
        default: null,
    },
});

const teamColorClasses = {
    green: {
        bg: 'bg-green-500',
        bgLight: 'bg-green-50',
        text: 'text-green-700',
        border: 'border-green-300',
        bar: 'bg-green-500',
        label: 'Verde',
    },
    yellow: {
        bg: 'bg-yellow-400',
        bgLight: 'bg-yellow-50',
        text: 'text-yellow-700',
        border: 'border-yellow-300',
        bar: 'bg-yellow-400',
        label: 'Amarelo',
    },
    blue: {
        bg: 'bg-blue-500',
        bgLight: 'bg-blue-50',
        text: 'text-blue-700',
        border: 'border-blue-300',
        bar: 'bg-blue-500',
        label: 'Azul',
    },
};

const sortedTeams = computed(() => {
    if (!props.prediction?.teams) return [];
    return [...props.prediction.teams].sort((a, b) => b.predicted_score - a.predicted_score);
});

const maxScore = computed(() => {
    if (!sortedTeams.value.length) return 1;
    return Math.max(...sortedTeams.value.map(t => t.predicted_score), 1);
});
</script>

<template>
    <div v-if="prediction" class="rounded-xl bg-white p-2 lg:p-4 shadow">
        <h3 class="mb-3 text-base font-semibold text-gray-900">
            <i class="fa-solid fa-brain mr-1 text-purple-500"></i>
            Previsão IA
        </h3>

        <!-- Placar previsto visual -->
        <div class="flex items-end justify-center gap-3 mb-4">
            <div v-for="team in sortedTeams" :key="team.color"
                class="flex flex-col items-center flex-1">
                <div class="text-3xl font-black tabular-nums mb-1"
                    :class="teamColorClasses[team.color]?.text">
                    {{ team.predicted_score }}
                </div>
                <div class="w-full rounded-t-lg transition-all duration-500"
                    :class="teamColorClasses[team.color]?.bar"
                    :style="{ height: Math.max((team.predicted_score / maxScore) * 80, 8) + 'px' }">
                </div>
                <div class="mt-1.5 flex items-center gap-1">
                    <span class="inline-block h-2.5 w-2.5 rounded-full"
                        :class="teamColorClasses[team.color]?.bg"></span>
                    <span class="text-xs font-semibold text-gray-700">
                        {{ teamColorClasses[team.color]?.label || team.label }}
                    </span>
                </div>
                <span v-if="team.color === prediction.predicted_winner"
                    class="mt-1 rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-bold text-purple-700">
                    <i class="fa-solid fa-trophy mr-0.5"></i>
                    Favorito
                </span>
            </div>
        </div>

        <!-- Probabilidades -->
        <div class="space-y-2">
            <div v-for="team in sortedTeams" :key="'prob-' + team.color"
                class="flex items-center gap-2">
                <span class="inline-block h-2.5 w-2.5 rounded-full shrink-0"
                    :class="teamColorClasses[team.color]?.bg"></span>
                <div class="flex-1 h-2 rounded-full bg-gray-200 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500"
                        :class="teamColorClasses[team.color]?.bar"
                        :style="{ width: team.win_probability + '%' }">
                    </div>
                </div>
                <span class="text-xs font-bold w-10 text-right"
                    :class="teamColorClasses[team.color]?.text">
                    {{ team.win_probability }}%
                </span>
            </div>
        </div>
    </div>
</template>
