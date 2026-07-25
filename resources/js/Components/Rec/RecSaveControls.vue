<script setup>
defineProps({
    recording: Boolean,
    canStart: Boolean,
    toggling: Boolean,
    registering: Boolean,
    saving: Boolean,
    cooldown: { type: Number, default: 0 },
    canLeft: Boolean,
    canAll: Boolean,
    canRight: Boolean,
});

defineEmits(['toggle', 'save']);
</script>

<template>
    <section class="grid grid-cols-1 gap-3">
        <button
            type="button"
            class="w-full rounded-2xl py-5 text-lg font-bold uppercase tracking-wider disabled:opacity-50"
            :class="recording ? 'bg-gray-800 text-white' : 'bg-red-600 text-white shadow-lg shadow-red-200'"
            :disabled="recording ? (toggling || registering) : !canStart"
            @click="$emit('toggle')"
        >
            <i class="fa-solid fa-circle-dot mr-2" />
            {{ recording ? 'Parar REC' : 'REC MODE' }}
        </button>
        <div class="grid grid-cols-[1fr_1.45fr_1fr] gap-2">
            <button
                type="button"
                class="rounded-2xl py-3 font-bold uppercase bg-emerald-700 text-white disabled:opacity-50"
                :disabled="!canLeft"
                @click="$emit('save', 'left')"
            >
                <i class="fa-solid fa-arrow-left block text-xl" />
                <span class="block text-xs mt-1">SAVE</span>
                <span class="block text-[10px] font-medium opacity-80">B1 + A1</span>
            </button>
            <button
                type="button"
                class="rounded-2xl py-4 font-bold uppercase bg-emerald-600 text-white disabled:opacity-50"
                :disabled="!canAll"
                @click="$emit('save', 'all')"
            >
                <i class="fa-solid fa-floppy-disk block text-xl mb-1" />
                {{ saving ? 'Salvando...' : cooldown > 0 ? `${cooldown}s` : 'SAVE REC' }}
            </button>
            <button
                type="button"
                class="rounded-2xl py-3 font-bold uppercase bg-emerald-700 text-white disabled:opacity-50"
                :disabled="!canRight"
                @click="$emit('save', 'right')"
            >
                <i class="fa-solid fa-arrow-right block text-xl" />
                <span class="block text-xs mt-1">SAVE</span>
                <span class="block text-[10px] font-medium opacity-80">B2 + A2</span>
            </button>
        </div>
    </section>
</template>
