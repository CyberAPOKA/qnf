<script setup>
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    rounds: Array,
    selected_game_id: Number,
    payments: Array,
});

const selectedGameId = ref(props.selected_game_id);

watch(selectedGameId, (newId) => {
    if (newId) {
        router.get(route('admin.payments'), { game_id: newId }, {
            preserveState: true,
            preserveScroll: true,
        });
    }
});

const confirmPayment = (paymentId) => {
    if (!paymentId) return;
    router.post(route('payments.confirm', paymentId), {}, {
        preserveScroll: true,
        preserveState: false,
    });
};

const columns = [
    { key: 'user_name', label: 'Jogador', class: 'font-medium text-gray-900' },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', align: 'center' },
];

const paidCount = () => props.payments.filter(p => p.paid_at).length;
const totalCount = () => props.payments.length;
</script>

<template>
    <AppLayout title="Pagamentos">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pagamentos</h2>
        </template>

        <div class="p-2 lg:p-4">
            <div class="mx-auto max-w-2xl space-y-4">
                <!-- Select de rodada -->
                <div class="rounded-xl bg-white p-4 shadow">
                    <label for="round-select" class="block text-sm font-semibold text-gray-700 mb-2">
                        Selecionar rodada
                    </label>
                    <select id="round-select" v-model="selectedGameId"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option v-for="round in rounds" :key="round.id" :value="round.id">
                            Rodada {{ round.round }} — {{ round.date }}
                            {{ round.status === 'done' ? '(Finalizado)' : '(Times definidos)' }}
                        </option>
                    </select>
                </div>

                <!-- Resumo -->
                <div v-if="payments.length" class="rounded-xl bg-white p-4 shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-gray-900">
                            <i class="fa-brands fa-pix text-[#32BCAD] mr-1"></i>
                            Pagamentos da rodada
                        </h3>
                        <div class="flex gap-2 text-xs">
                            <span class="rounded-full bg-green-100 px-2.5 py-1 text-green-700 font-semibold">
                                {{ paidCount() }}/{{ totalCount() }} pagos
                            </span>
                        </div>
                    </div>

                    <DataTable :columns="columns" :rows="payments" row-key="user_id"
                        empty-message="Nenhum jogador de linha nesta rodada.">
                        <template #cell-status="{ row }">
                            <span v-if="row.paid_at"
                                class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                <i class="fa-solid fa-circle-check"></i>
                                Pago em {{ row.paid_at }}
                            </span>
                            <span v-else
                                class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                <i class="fa-solid fa-clock"></i>
                                PENDENTE
                                <template v-if="row.penalty_rounds > 0">
                                    ({{ row.penalty_rounds >= 3 ? 'Suspenso' : row.penalty_rounds + 'r' }})
                                </template>
                            </span>
                        </template>
                        <template #cell-actions="{ row }">
                            <button v-if="!row.paid_at && row.payment_id" @click="confirmPayment(row.payment_id)"
                                class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 transition">
                                Confirmar
                            </button>
                        </template>
                    </DataTable>
                </div>

                <div v-else-if="!rounds.length" class="rounded-xl bg-white p-6 shadow text-center text-sm text-gray-500">
                    Nenhuma rodada com times definidos ainda.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
