<script setup>
import RecClipPlayer from './RecClipPlayer.vue';

const props = defineProps({
    save: { type: Object, required: true },
    pending: Object,
    v2: Boolean,
});

const order = { B1: 0, B2: 1, A1: 2, A2: 3 };

function clips() {
    return [...(props.save.clips || [])].sort((a, b) =>
        (order[a.camera_tag] ?? 99) - (order[b.camera_tag] ?? 99));
}

function time(iso) {
    return iso
        ? new Date(iso).toLocaleTimeString('pt-BR', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        })
        : '';
}
</script>

<template>
    <article class="rounded-2xl bg-white shadow overflow-hidden">
        <header class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-900">
                {{ time(save.triggered_at) }} por {{ save.triggered_by || save.triggered_by_name }}
            </p>
            <span v-if="pending" class="text-xs font-medium px-2 py-1 rounded-full bg-amber-100 text-amber-700">
                {{ pending.received || 0 }}/{{ pending.expected || 0 }} câmeras
            </span>
        </header>
        <div v-if="v2 && (save.targets?.length || pending?.targets?.length)" class="px-4 py-2 border-b space-y-1">
            <div
                v-for="target in (save.targets || pending.targets)"
                :key="target.uuid || target.camera_tag"
                class="flex justify-between text-xs"
            >
                <span class="font-semibold">{{ target.camera_tag }}</span>
                <span class="text-gray-600">{{ target.status_label || target.status }}</span>
            </div>
        </div>
        <div v-if="clips().length" class="p-3 grid grid-cols-2 gap-2">
            <RecClipPlayer v-for="clip in clips()" :key="clip.id || clip.uuid || clip.recorder_id" :clip="clip" />
        </div>
        <div v-else-if="pending?.status === 'failed'" class="px-4 py-6 text-center text-sm text-red-600">
            {{ pending.error || 'Falha ao salvar o clip.' }}
        </div>
        <div v-else class="px-4 py-6 text-center text-sm text-gray-500">
            <i class="fa-solid fa-spinner fa-spin mr-1" /> Aguardando câmeras...
        </div>
    </article>
</template>
