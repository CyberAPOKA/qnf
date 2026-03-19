<script setup>
import DataTable from '@/Components/DataTable.vue';
import PositionBadge from '@/Components/Game/PositionBadge.vue';

defineProps({
    ranking: {
        type: Array,
        default: () => [],
    },
});

const positionLabels = {
    fixed: 'Fixo',
    winger: 'Ala',
    pivot: 'Pivô',
};

const medalColors = {
    1: 'text-[#B8860B]',
    2: 'text-[#6B7280]',
    3: 'text-[#8C4A2F]',
};

const rowBgColors = {
    1: 'bg-[#FFF4CC]',
    2: 'bg-[#F1F5F9]',
    3: 'bg-[#FCE7DF]',
};

const rowClass = (row) => {
    if (row.total_score === 0) return 'bg-red-300';
    return rowBgColors[row.rank] || '';
};

const columns = [
    { key: 'rank', label: '#' },
    { key: 'name', label: 'Jogador', class: 'font-bold text-lg text-gray-900' },
    { key: 'total_score', label: 'Vitórias', align: 'center', class: 'font-bold text-lg text-gray-900' },
    { key: 'games_played', label: 'Jogos', align: 'center', class: 'font-bold text-lg text-gray-900' },
    { key: 'avg_score', label: 'Média', align: 'center', class: 'font-bold text-lg text-gray-900' },
];
</script>

<template>
    <div class="rounded-xl bg-white p-2 lg:p-4 shadow">
        <h3 class="mb-3 text-base font-semibold text-gray-900">
            <i class="fa-solid fa-ranking-star mr-1 text-amber-500"></i>
            Ranking de Vitórias
        </h3>

        <DataTable :columns="columns" :rows="ranking" :row-class="rowClass"
            empty-message="Nenhum jogo finalizado ainda.">
            <template #cell-rank="{ row }">
                <span v-if="row.rank <= 3 && row.total_score > 0" class="text-lg">
                    <i class="!text-2xl fa-solid fa-medal drop-shadow-[0_1px_1px_rgba(0,0,0,0.25)]"
                        :class="medalColors[row.rank]"></i>
                </span>
                <span v-else class="font-bold text-lg text-gray-900">{{ row.rank }}º</span>
            </template>
            <template #cell-name="{ row }">
                <div class="flex items-center gap-2">
                    <span class="font-medium text-gray-900">{{ row.name }}</span>
                    <PositionBadge :position="row.position" :label="positionLabels[row.position] || row.position" />
                </div>
            </template>
        </DataTable>
    </div>
</template>
