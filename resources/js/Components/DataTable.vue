<script setup>
const props = defineProps({
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
    numericSort: {
        type: Boolean,
        default: false,
    },
    striped: {
        type: Boolean,
        default: false,
    },
    grid: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['sort']);

const alignClass = (align) => {
    if (align === 'center') return 'text-center';
    if (align === 'right') return 'text-right';
    return 'text-left';
};

const gridBorderClass = (isLast) => (props.grid && !isLast ? 'border-r border-gray-200' : '');

const cellPaddingClass = props.grid
    ? 'px-2 py-1 lg:px-3 lg:py-1.5'
    : 'py-1 pr-1 lg:pr-2 last:pr-0';

const headerPaddingClass = props.grid
    ? 'px-2 pb-1 lg:px-3 lg:pb-2'
    : 'pb-1 pr-1 lg:pb-2 lg:pr-2 last:pr-0';

const rowStripeClass = (index) => (index % 2 === 0 ? 'bg-white' : 'bg-gray-50');

const onSort = (key) => emit('sort', key);
</script>

<template>
    <div class="overflow-x-auto">
        <p v-if="!rows.length" class="text-sm text-gray-900">{{ emptyMessage }}</p>

        <table v-else class="w-full text-sm" :class="grid ? 'border-collapse' : ''">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase text-gray-900"
                    :class="grid ? 'bg-gray-50' : ''">
                    <th v-if="numbered"
                        :class="[headerPaddingClass, gridBorderClass(false), 'font-bold text-gray-900']">#</th>
                    <th v-for="(col, colIndex) in columns" :key="col.key"
                        :class="[
                            alignClass(col.align),
                            col.class,
                            headerPaddingClass,
                            gridBorderClass(colIndex < columns.length - 1),
                            col.sortable ? 'cursor-pointer select-none hover:text-indigo-600' : '',
                        ]"
                        @click="col.sortable ? onSort(col.key) : undefined">
                        <span class="inline-flex items-center gap-1" :class="alignClass(col.align) === 'text-center' ? 'justify-center w-full' : ''">
                            <slot :name="'header-' + col.key" :column="col">
                                {{ col.label }}
                            </slot>
                            <i v-if="col.sortable && sortKey === col.key"
                                class="fa-solid text-[10px]"
                                :class="numericSort
                                    ? (sortDir === 'asc' ? 'fa-arrow-up-1-9' : 'fa-arrow-down-1-9')
                                    : (sortDir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down')"
                            ></i>
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="row[rowKey] ?? index"
                    :class="[
                        striped ? rowStripeClass(index) : '',
                        grid ? '' : 'border-b last:border-0',
                        rowClass ? rowClass(row, index) : '',
                    ]">
                    <td v-if="numbered"
                        :class="[cellPaddingClass, gridBorderClass(false), 'font-bold text-gray-900']">
                        {{ index + 1 }}
                    </td>
                    <td v-for="(col, colIndex) in columns" :key="col.key"
                        :class="[
                            alignClass(col.align),
                            col.class,
                            cellPaddingClass,
                            gridBorderClass(colIndex < columns.length - 1),
                        ]">
                        <slot :name="'cell-' + col.key" :row="row" :value="row[col.key]" :index="index">
                            {{ row[col.key] }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
