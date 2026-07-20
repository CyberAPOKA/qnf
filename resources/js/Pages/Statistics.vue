<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    statistics: Object,
});

const search = ref('');
const positionFilter = ref('all');
const sortBy = ref('games_together');

const sortOptions = [
    { value: 'games_together', label: 'Mais jogos juntos' },
    { value: 'together_rate', label: 'Melhor parceiro' },
    { value: 'games_against', label: 'Adversário mais enfrentado' },
    { value: 'patinhos', label: 'Patinhos' },
    { value: 'carrascos', label: 'Carrascos' },
];

const positionOptions = [
    { value: 'all', label: 'Todos' },
    { value: 'line', label: 'Linha' },
    { value: 'goalkeepers', label: 'Goleiros' },
];

const firstName = (name) => name?.trim().split(/\s+/)[0] ?? '';

const performanceRate = (wins, draws, games) => {
    if (!games) {
        return null;
    }

    return ((wins * 3) + draws) / (games * 3) * 100;
};

// Tie de 2 equipes conta como vitória no aproveitamento.
const performanceRateWithTie2 = (wins, tie2, games) => {
    if (!games) {
        return null;
    }

    return ((wins + tie2) / games) * 100;
};

const formatRate = (rate) => (rate == null ? '—' : `${rate.toFixed(1).replace('.', ',')}%`);

const togetherSection = (partner) => ({
    games: partner.games_together,
    wins: partner.wins_together,
    draws: partner.draws_together,
    losses: partner.losses_together,
    rate: performanceRateWithTie2(partner.wins_together, partner.tie2_together, partner.games_together),
});

const againstSection = (partner) => {
    return {
        games: partner.games_against,
        wins: partner.wins_against,
        draws: partner.draws_against,
        losses: partner.losses_against,
        rate: performanceRateWithTie2(partner.wins_against, partner.tie2_against, partner.games_against),
    };
};

const defaultPartners = computed(() => props.statistics?.partners ?? []);

const filteredPartners = computed(() => {
    let list = defaultPartners.value;

    if (positionFilter.value === 'line') {
        list = list.filter((partner) => !partner.is_goalkeeper);
    } else if (positionFilter.value === 'goalkeepers') {
        list = list.filter((partner) => partner.is_goalkeeper);
    }

    const term = search.value.trim().toLowerCase();
    if (term) {
        list = list.filter((partner) => partner.name?.toLowerCase().includes(term));
    }

    if (sortBy.value === 'patinhos') {
        list = list.filter((partner) => partner.wins_against > partner.losses_against);
    } else if (sortBy.value === 'carrascos') {
        list = list.filter((partner) => partner.losses_against > partner.wins_against);
    }

    return list;
});

const emptyMessage = computed(() => {
    if (search.value.trim()) {
        return 'Nenhum jogador encontrado com esse nome.';
    }

    if (sortBy.value === 'patinhos') {
        return 'Nenhum patinho encontrado (vitórias contra maiores que derrotas).';
    }

    if (sortBy.value === 'carrascos') {
        return 'Nenhum carrasco encontrado (derrotas contra maiores que vitórias).';
    }

    return 'Nenhum parceiro ou confronto registrado ainda.';
});

const sortValue = (partner) => {
    if (sortBy.value === 'together_rate') {
        return togetherSection(partner).rate ?? -1;
    }

    if (sortBy.value === 'patinhos') {
        return partner.wins_against;
    }

    if (sortBy.value === 'carrascos') {
        return partner.losses_against;
    }

    return partner[sortBy.value] ?? 0;
};

const displayedPartners = computed(() => [...filteredPartners.value].sort((a, b) => {
    const diff = sortValue(b) - sortValue(a);

    if (diff !== 0) {
        return diff;
    }

    if (a.games_together !== b.games_together) {
        return b.games_together - a.games_together;
    }

    return a.name.localeCompare(b.name, 'pt-BR');
}));

const rowStripeClass = (index) => (index % 2 === 0 ? 'bg-white' : 'bg-gray-50');

const rateBarClass = (rate) => {
    if (rate == null) {
        return 'bg-gray-200';
    }

    if (rate >= 66) {
        return 'bg-green-500';
    }

    if (rate >= 33) {
        return 'bg-yellow-500';
    }

    return 'bg-red-500';
};

const cardSections = (partner) => [
    { title: 'Jogando juntos', data: togetherSection(partner), gamesLabel: 'jogos', showDraws: true },
    { title: 'Como adversários', data: againstSection(partner), gamesLabel: 'confrontos', showDraws: true },
];
</script>

<template>
    <AppLayout title="Estatísticas">
        <template #header>
            <TitleCard />
        </template>

        <div class="p-1 lg:p-4">
            <div class="mx-auto max-w-6xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow">
                    <div class="mb-4 space-y-3">
                        <h3 class="text-base font-semibold text-gray-900">Parceiros e confrontos</h3>

                        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                            <TextInput
                                v-model="search"
                                type="search"
                                placeholder="Buscar por nome..."
                                class="w-full text-sm sm:max-w-xs"
                            />
                            <select
                                v-model="positionFilter"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:w-auto"
                            >
                                <option v-for="option in positionOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <select
                                v-model="sortBy"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:w-auto"
                            >
                                <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <p v-if="!displayedPartners.length" class="text-sm text-gray-900">{{ emptyMessage }}</p>

                    <div v-else class="hidden overflow-x-auto lg:block">
                        <table class="w-full border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase text-gray-900">
                                    <th rowspan="2" class="border-r border-gray-200 px-3 py-2 text-left">Jogador</th>
                                    <th colspan="5" class="border-r border-gray-200 px-3 py-2 text-center">Jogando juntos</th>
                                    <th colspan="5" class="px-3 py-2 text-center">Como adversários</th>
                                </tr>
                                <tr class="border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase text-gray-700">
                                    <th class="border-r border-gray-200 px-2 py-2 text-center">Jogos</th>
                                    <th class="border-r border-gray-200 px-2 py-2 text-center text-green-700">Vitórias</th>
                                    <th class="border-r border-gray-200 px-2 py-2 text-center text-yellow-700">Empates</th>
                                    <th class="border-r border-gray-200 px-2 py-2 text-center text-red-700">Derrotas</th>
                                    <th class="border-r border-gray-200 px-2 py-2 text-center">Aproveit.</th>
                                    <th class="border-r border-gray-200 px-2 py-2 text-center">Jogos</th>
                                    <th class="border-r border-gray-200 px-2 py-2 text-center text-green-700">Você venceu</th>
                                    <th class="border-r border-gray-200 px-2 py-2 text-center text-yellow-700">Empates</th>
                                    <th class="border-r border-gray-200 px-2 py-2 text-center text-red-700">Você perdeu</th>
                                    <th class="px-2 py-2 text-center">Aproveit.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(partner, index) in displayedPartners"
                                    :key="partner.id"
                                    :class="rowStripeClass(index)"
                                >
                                    <td class="border-r border-gray-200 px-3 py-2 font-bold text-gray-900">
                                        <span class="inline-flex items-center gap-1.5">
                                            {{ firstName(partner.name) }}
                                            <i
                                                v-if="partner.is_goalkeeper"
                                                class="fa-solid fa-mitten text-gray-900"
                                                title="Goleiro"
                                            ></i>
                                        </span>
                                    </td>

                                    <template v-for="section in [togetherSection(partner)]" :key="'together'">
                                        <td class="border-r border-gray-200 px-2 py-2 text-center font-semibold text-gray-900">{{ section.games }}</td>
                                        <td class="border-r border-gray-200 px-2 py-2 text-center font-semibold text-green-700">{{ section.wins }}</td>
                                        <td class="border-r border-gray-200 px-2 py-2 text-center font-semibold text-yellow-700">{{ section.draws }}</td>
                                        <td class="border-r border-gray-200 px-2 py-2 text-center font-semibold text-red-700">{{ section.losses }}</td>
                                        <td class="border-r border-gray-200 px-2 py-2 text-center">
                                            <div class="mx-auto max-w-[88px]">
                                                <div class="text-xs font-semibold text-gray-900">{{ formatRate(section.rate) }}</div>
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-200">
                                                    <div
                                                        class="h-full rounded-full transition-all"
                                                        :class="rateBarClass(section.rate)"
                                                        :style="{ width: `${section.rate ?? 0}%` }"
                                                    ></div>
                                                </div>
                                            </div>
                                        </td>
                                    </template>

                                    <template v-for="section in [againstSection(partner)]" :key="'against'">
                                        <td class="border-r border-gray-200 px-2 py-2 text-center font-semibold text-gray-900">{{ section.games }}</td>
                                        <td class="border-r border-gray-200 px-2 py-2 text-center font-semibold text-green-700">{{ section.wins }}</td>
                                        <td class="border-r border-gray-200 px-2 py-2 text-center font-semibold text-yellow-700">{{ section.draws }}</td>
                                        <td class="border-r border-gray-200 px-2 py-2 text-center font-semibold text-red-700">{{ section.losses }}</td>
                                        <td class="px-2 py-2 text-center">
                                            <div class="mx-auto max-w-[88px]">
                                                <div class="text-xs font-semibold text-gray-900">{{ formatRate(section.rate) }}</div>
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-200">
                                                    <div
                                                        class="h-full rounded-full transition-all"
                                                        :class="rateBarClass(section.rate)"
                                                        :style="{ width: `${section.rate ?? 0}%` }"
                                                    ></div>
                                                </div>
                                            </div>
                                        </td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-3 lg:hidden">
                        <article
                            v-for="partner in displayedPartners"
                            :key="partner.id"
                            class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
                        >
                            <div class="mb-3">
                                <span class="inline-flex items-center gap-1.5 text-base font-bold text-gray-900">
                                    {{ firstName(partner.name) }}
                                    <i
                                        v-if="partner.is_goalkeeper"
                                        class="fa-solid fa-mitten text-gray-900"
                                        title="Goleiro"
                                    ></i>
                                </span>
                            </div>

                            <section
                                v-for="(section, sectionIndex) in cardSections(partner)"
                                :key="section.title"
                                :class="sectionIndex > 0 ? 'mt-4 border-t border-gray-100 pt-4' : ''"
                            >
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <h4 class="text-sm font-semibold text-gray-900">{{ section.title }}</h4>
                                    <span class="text-xs font-medium text-gray-500">
                                        {{ section.data.games }} {{ section.gamesLabel }}
                                    </span>
                                </div>

                                <p class="text-sm text-gray-700">
                                    <span class="font-semibold text-green-700">{{ section.data.wins }} vitórias</span>
                                    <template v-if="section.showDraws">
                                        <span class="text-gray-400"> · </span>
                                        <span class="font-semibold text-yellow-700">{{ section.data.draws }} empate{{ section.data.draws === 1 ? '' : 's' }}</span>
                                    </template>
                                    <span class="text-gray-400"> · </span>
                                    <span class="font-semibold text-red-700">{{ section.data.losses }} derrota{{ section.data.losses === 1 ? '' : 's' }}</span>
                                </p>

                                <div class="mt-2">
                                    <div class="mb-1 flex items-center justify-between text-xs text-gray-600">
                                        <span>Aproveitamento</span>
                                        <span class="font-semibold text-gray-900">{{ formatRate(section.data.rate) }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                                        <div
                                            class="h-full rounded-full transition-all"
                                            :class="rateBarClass(section.data.rate)"
                                            :style="{ width: `${section.data.rate ?? 0}%` }"
                                        ></div>
                                    </div>
                                </div>
                            </section>
                        </article>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
