<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import { router } from '@inertiajs/vue3';

defineProps({
    publications: {
        type: Array,
        default: () => [],
    },
});

const columns = [
    { key: 'type_label', label: 'Tipo', class: 'font-medium text-gray-900' },
    { key: 'trigger_label', label: 'Trigger' },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'attempts', label: 'Tentativas', align: 'center' },
    { key: 'error', label: 'Erro' },
    { key: 'permalink', label: 'Permalink' },
    { key: 'actions', label: '', align: 'center' },
];

const statusClass = (status) => {
    if (status === 'published' || status === 'dry_run_completed') {
        return 'bg-green-100 text-green-700';
    }
    if (status === 'failed') {
        return 'bg-red-100 text-red-700';
    }
    if (status === 'cancelled') {
        return 'bg-gray-100 text-gray-600';
    }
    return 'bg-amber-100 text-amber-800';
};

const retryPublication = (uuid) => {
    router.post(route('admin.instagram.retry', uuid), {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout title="Instagram">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Instagram</h2>
        </template>

        <div class="p-1 lg:p-4">
            <div class="mx-auto max-w-5xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-gray-900">
                            Publicações recentes
                        </h3>
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">
                            {{ publications.length }}
                        </span>
                    </div>

                    <DataTable
                        :columns="columns"
                        :rows="publications"
                        row-key="uuid"
                        empty-message="Nenhuma publicação Instagram encontrada."
                    >
                        <template #cell-status="{ row }">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="statusClass(row.status)"
                            >
                                {{ row.status }}
                            </span>
                        </template>

                        <template #cell-error="{ row }">
                            <span class="text-xs text-gray-600 break-words" :title="row.error || ''">
                                {{ row.error || '—' }}
                            </span>
                        </template>

                        <template #cell-permalink="{ row }">
                            <a
                                v-if="row.permalink"
                                :href="row.permalink"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-xs text-indigo-600 hover:underline"
                            >
                                Abrir
                            </a>
                            <span v-else class="text-xs text-gray-400">—</span>
                        </template>

                        <template #cell-actions="{ row }">
                            <button
                                v-if="row.can_retry"
                                type="button"
                                class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 transition"
                                @click="retryPublication(row.uuid)"
                            >
                                Retry
                            </button>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
