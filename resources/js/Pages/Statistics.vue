<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import TitleCard from '@/Components/Game/TitleCard.vue';
import DataTable from '@/Components/DataTable.vue';

const props = defineProps({
    statistics: Object,
});

const playedColumns = [
    { key: 'name', label: 'Jogador', class: 'font-bold text-gray-900' },
    { key: 'count', label: 'Jogos juntos', align: 'center', class: 'font-bold text-gray-900' },
];

const wonColumns = [
    { key: 'name', label: 'Jogador', class: 'font-bold text-gray-900' },
    { key: 'count', label: 'Vitórias juntos', align: 'center', class: 'font-bold text-gray-900' },
];
</script>

<template>
    <AppLayout title="Estatísticas">
        <template #header>
            <TitleCard />
        </template>

        <div class="p-1 lg:p-4">
            <div class="mx-auto max-w-3xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow">
                    <h3 class="mb-3 text-base font-semibold text-gray-900">Mais joguei com</h3>
                    <DataTable
                        :columns="playedColumns"
                        :rows="statistics.played_with"
                        numbered
                        empty-message="Nenhum parceiro com 2+ jogos juntos ainda."
                    />
                </div>

                <div class="rounded-xl bg-white p-4 shadow">
                    <h3 class="mb-3 text-base font-semibold text-gray-900">Mais venci com</h3>
                    <DataTable
                        :columns="wonColumns"
                        :rows="statistics.won_with"
                        numbered
                        empty-message="Nenhuma vitória em dupla registrada ainda."
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
