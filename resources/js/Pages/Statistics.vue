<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import HudPageTitle from '@/Components/HudPageTitle.vue';
import HudInput from '@/Components/HudInput.vue';
import HudSelect from '@/Components/HudSelect.vue';
import PartnerStatsCard from '@/Components/Statistics/PartnerStatsCard.vue';

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

// Tie de 2 equipes conta como vitória no aproveitamento.
const performanceRateWithTie2 = (wins, tie2, games) => {
    if (!games) {
        return null;
    }

    return ((wins + tie2) / games) * 100;
};

const togetherSection = (partner) => ({
    games: partner.games_together,
    wins: partner.wins_together,
    draws: partner.draws_together,
    losses: partner.losses_together,
    rate: performanceRateWithTie2(partner.wins_together, partner.tie2_together, partner.games_together),
});

const againstSection = (partner) => ({
    games: partner.games_against,
    wins: partner.wins_against,
    draws: partner.draws_against,
    losses: partner.losses_against,
    rate: performanceRateWithTie2(partner.wins_against, partner.tie2_against, partner.games_against),
});

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

const displayedPartners = computed(() => [...filteredPartners.value]
    .sort((a, b) => {
        const diff = sortValue(b) - sortValue(a);

        if (diff !== 0) {
            return diff;
        }

        if (a.games_together !== b.games_together) {
            return b.games_together - a.games_together;
        }

        return a.name.localeCompare(b.name, 'pt-BR');
    })
    .map((partner) => ({
        id: partner.id,
        name: firstName(partner.name),
        photo: partner.photo_front ?? null,
        isGoalkeeper: partner.is_goalkeeper,
        gamesTogether: partner.games_together,
        gamesAgainst: partner.games_against,
        together: togetherSection(partner),
        against: againstSection(partner),
    })));
</script>

<template>
    <AppLayout title="Estatísticas">
        <div class="px-1 py-4 pb-24 sm:px-4 lg:px-8 lg:py-6">
            <div class="mx-auto max-w-6xl space-y-2">
                <HudPageTitle title="Parceiros e Confrontos" :back-href="route('dashboard')" />

                <div class="grid gap-3 md:grid-cols-3">
                    <HudInput
                        v-model="search"
                        type="search"
                        placeholder="Buscar por nome..."
                        icon="fa-solid fa-magnifying-glass"
                        clearable
                        class="md:col-span-1"
                    />

                    <HudSelect
                        v-model="positionFilter"
                        variant="field"
                        block
                        icon="fa-solid fa-filter"
                        :options="positionOptions"
                        option-label="label"
                        option-value="value"
                    />

                    <HudSelect
                        v-model="sortBy"
                        variant="field"
                        block
                        icon="fa-solid fa-user-group"
                        :options="sortOptions"
                        option-label="label"
                        option-value="value"
                    />
                </div>

                <p v-if="!displayedPartners.length" class="statistics__empty">
                    {{ emptyMessage }}
                </p>

                <div v-else class="grid gap-2">
                    <PartnerStatsCard
                        v-for="partner in displayedPartners"
                        :key="partner.id"
                        :partner="partner"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.statistics__empty {
    margin: 0;
    padding: 32px 16px;
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 14px;
    background: rgba(8, 14, 28, 0.7);
    color: #94a3b8;
    font-size: 0.9rem;
    font-weight: 600;
    text-align: center;
}
</style>
