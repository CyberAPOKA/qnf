<script setup>
import PositionBadge from '@/Components/Game/PositionBadge.vue';

defineProps({
    players: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="rounded-xl bg-white p-4 shadow" v-if="players.length">
        <h3 class="text-base font-semibold text-gray-900">Inscritos</h3>
        <ul class="mt-3 space-y-2">
            <li v-for="player in players" :key="player.id"
                class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-gray-900">{{ player.name }}</span>
                    <span v-if="player.guest"
                        class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-700">
                        Convidado
                    </span>
                </div>
                <PositionBadge :position="player.position" :label="player.position_label" />
            </li>
        </ul>
    </div>
</template>
