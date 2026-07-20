<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import TeamCard from '@/Components/Game/TeamCard.vue';

const props = defineProps({
    rounds: {
        type: Array,
        default: () => [],
    },
    current_user_id: Number,
});

const formatDate = (date) => {
    if (!date) return '';

    const parsed = new Date(date);
    if (Number.isNaN(parsed.getTime())) return '';

    return parsed.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const roundScores = (teams) => {
    if (!teams) return null;

    const scores = ['green', 'yellow', 'blue']
        .map((color) => teams[color]?.score)
        .filter((score) => score != null);

    return scores.length ? scores.join(' · ') : null;
};

const hasRounds = computed(() => props.rounds.length > 0);

const playerResult = (teams) => {
    if (!teams) return null;

    const colors = ['green', 'yellow', 'blue'];
    let playerColor = null;

    for (const color of colors) {
        const team = teams[color];
        if (!team) continue;
        if (team.captain?.id === props.current_user_id) { playerColor = color; break; }
        if (team.players?.some((p) => p.id === props.current_user_id)) { playerColor = color; break; }
    }

    if (!playerColor) return null;

    const playerScore = teams[playerColor]?.score;
    if (playerScore == null) return null;

    const otherScores = colors
        .filter((c) => c !== playerColor)
        .map((c) => teams[c]?.score)
        .filter((s) => s != null);

    if (!otherScores.length) return null;

    const maxOther = Math.max(...otherScores);

    if (playerScore > maxOther) return 'win';
    if (playerScore === maxOther) return 'draw';
    return 'loss';
};

const resultConfig = {
    win: { label: 'Vitória', classes: 'bg-green-100 text-green-800' },
    draw: { label: 'Empate', classes: 'bg-yellow-100 text-yellow-800' },
    loss: { label: 'Derrota', classes: 'bg-red-100 text-red-800' },
};
</script>

<template>
    <AppLayout title="Rodadas">
        <template #header>
            <TitleCard />
        </template>

        <div class="px-1 py-2 sm:p-2 lg:p-4">
            <div class="mx-auto max-w-3xl space-y-3">
                <div class="rounded-xl bg-white p-4 shadow">
                    <h2 class="text-lg font-semibold text-gray-900">Rodadas</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Times, jogadores e pontuações de todas as rodadas.
                    </p>
                </div>

                <div v-if="!hasRounds" class="rounded-xl bg-white p-6 shadow text-center text-sm text-gray-500">
                    Nenhuma rodada com times formados ainda.
                </div>

                <div v-for="round in rounds" :key="round.round" class="rounded-xl bg-white shadow overflow-hidden">
                    <div class="flex items-center justify-between gap-3 p-4">
                        <div>
                            <p class="text-base font-semibold text-gray-900 flex items-center gap-2">
                                Rodada {{ round.round }}
                                <span
                                    v-if="playerResult(round.teams)"
                                    class="rounded px-2 py-0.5 text-xs font-semibold"
                                    :class="resultConfig[playerResult(round.teams)].classes"
                                >
                                    {{ resultConfig[playerResult(round.teams)].label }}
                                </span>
                            </p>
                            <p v-if="round.date" class="text-sm text-gray-500">
                                {{ formatDate(round.date) }}
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            <span
                                v-if="roundScores(round.teams)"
                                class="text-sm font-semibold text-gray-700 tabular-nums"
                            >
                                {{ roundScores(round.teams) }}
                            </span>
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">
                                {{ round.status_label }}
                            </span>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 p-2 lg:p-4">
                        <div class="grid grid-cols-3 gap-1 lg:gap-2">
                            <TeamCard color="green" :team="round.teams?.green" />
                            <TeamCard color="yellow" :team="round.teams?.yellow" />
                            <TeamCard color="blue" :team="round.teams?.blue" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
