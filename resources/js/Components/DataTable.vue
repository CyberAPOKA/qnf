<script setup>
defineProps({
    columns: {
        type: Array,
        required: true,
        // Each column: { key: string, label: string, align?: 'left'|'center'|'right', class?: string, sortable?: boolean }
    },
    rows: {
        type: Array,
        default: () => [],
    },
    rowKey: {
        type: String,
        default: 'id',
    },
    numbered: {
        type: Boolean,
        default: false,
    },
    rowClass: {
        type: Function,
        default: null,
    },
    emptyMessage: {
        type: String,
        default: 'Nenhum registro encontrado.',
    },
    sortKey: {
        type: String,
        default: null,
    },
    sortDir: {
        type: String,
        default: 'asc',
    },
});

const emit = defineEmits(['sort']);

const alignClass = (align) => {
    if (align === 'center') return 'text-center';
    if (align === 'right') return 'text-right';
    return 'text-left';
};

const onSort = (key) => emit('sort', key);
</script>

<template>
    <div class="overflow-x-auto">
        <p v-if="!rows.length" class="text-sm text-gray-900">{{ emptyMessage }}</p>

        <table v-else class="w-full text-sm">
            <thead>
                <tr class="border-b text-left text-xs font-semibold uppercase text-gray-900">
                    <th v-if="numbered" class="pb-1 pr-1 lg:pb-2 lg:pr-2">#</th>
                    <th v-for="col in columns" :key="col.key" class="pb-1 pr-1 lg:pb-2 lg:pr-2 last:pr-0"
                        :class="[alignClass(col.align), col.class, col.sortable ? 'cursor-pointer select-none hover:text-indigo-600' : '']"
                        @click="col.sortable ? onSort(col.key) : undefined">
                        <span class="inline-flex items-center gap-1" :class="alignClass(col.align) === 'text-center' ? 'justify-center w-full' : ''">
                            {{ col.label }}
                            <i v-if="col.sortable && sortKey === col.key"
                                class="fa-solid text-[10px]"
                                :class="sortDir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down'"
                            ></i>
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="row[rowKey] ?? index" class="border-b last:border-0"
                    :class="rowClass ? rowClass(row, index) : ''">
                    <td v-if="numbered" class="py-1 pr-1 lg:pr-2 font-bold text-gray-900">{{ index + 1 }}</td>
                    <td v-for="col in columns" :key="col.key" class="py-1 pr-1 lg:pr-2 last:pr-0"
                        :class="[alignClass(col.align), col.class]">
                        <slot :name="'cell-' + col.key" :row="row" :value="row[col.key]" :index="index">
                            {{ row[col.key] }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
