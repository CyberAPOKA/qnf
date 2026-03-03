<script setup>
import { computed } from 'vue';

const props = defineProps({
    color: {
        type: String,
        required: true,
    },
    team: {
        type: Object,
        default: null,
    },
});

const colorConfig = {
    green: {
        label: 'Time Verde',
        bg: 'bg-green-50',
        text: 'text-green-900',
        dot: 'text-green-500',
        border: 'border-green-600',
    },
    yellow: {
        label: 'Time Amarelo',
        bg: 'bg-yellow-50',
        text: 'text-yellow-900',
        dot: 'text-yellow-500',
        border: 'border-yellow-600',
    },
    blue: {
        label: 'Time Azul',
        bg: 'bg-blue-50',
        text: 'text-blue-900',
        dot: 'text-blue-500',
        border: 'border-blue-600',
    },
};

const config = computed(() => colorConfig[props.color] || colorConfig.green);

const members = computed(() => {
    const list = [];

    if (props.team?.captain) {
        list.push({
            id: props.team.captain.id,
            name: props.team.captain.name,
            badgeIcon: 'fa-solid fa-copyright',
            badgeClass: 'text-gray-900',
        });
    }

    for (const player of props.team?.players || []) {
        let badgeIcon = '';
        let badgeClass = '';
        if (player.position === 'goalkeeper') {
            badgeIcon = 'fa-solid fa-mitten';
            badgeClass = 'text-gray-900';
        } else if (player.is_first_pick) {
            badgeIcon = 'fa-solid fa-1';
            badgeClass = 'text-gray-900';
        }
        list.push({
            id: player.id,
            name: player.name,
            badgeIcon,
            badgeClass,
        });
    }

    return list;
});
</script>

<template>
    <div class="rounded-xl p-4 border" :class="[config.bg, config.text, config.border]">
        <div class="mb-2 flex items-center justify-between">
            <p class="text-lg font-bold">{{ config.label }}</p>
            <span v-if="team?.score != null" class="text-lg font-bold">{{ team.score }}</span>
        </div>
        <ul class="space-y-1 text-sm">
            <li v-for="member in members" :key="member.id" class="flex items-center gap-1.5">
                <i class="fa-solid fa-circle text-[12px]" :class="config.dot"></i>
                <span class="text-base font-bold">{{ member.name }}</span>
                <i v-if="member.badgeIcon" :class="[member.badgeIcon, member.badgeClass]" class=""></i>
            </li>
        </ul>
    </div>
</template>
