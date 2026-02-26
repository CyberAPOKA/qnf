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

const colorEmojis = {
    green: '🟢',
    yellow: '🟡',
    blue: '🔵',
};

const colorConfig = {
    green: {
        label: 'Time Verde',
        bg: 'bg-green-50',
        text: 'text-green-900',
    },
    yellow: {
        label: 'Time Amarelo',
        bg: 'bg-yellow-50',
        text: 'text-yellow-900',
    },
    blue: {
        label: 'Time Azul',
        bg: 'bg-blue-50',
        text: 'text-blue-900',
    },
};

const config = computed(() => colorConfig[props.color] || colorConfig.green);
const emoji = computed(() => colorEmojis[props.color] || '🟢');

const members = computed(() => {
    const list = [];

    if (props.team?.captain) {
        list.push({
            id: props.team.captain.id,
            name: props.team.captain.name,
            badge: '©️',
        });
    }

    for (const player of props.team?.players || []) {
        let badge = '';
        if (player.position === 'goalkeeper') {
            badge = '🧤';
        } else if (player.is_first_pick) {
            badge = '🔟';
        }
        list.push({
            id: player.id,
            name: player.name,
            badge,
        });
    }

    return list;
});
</script>

<template>
    <div class="rounded-xl p-4" :class="[config.bg, config.text]">
        <p class="mb-2 text-sm font-semibold">{{ config.label }}</p>
        <ul class="space-y-1 text-sm">
            <li v-for="member in members" :key="member.id">
                {{ emoji }} {{ member.name }}{{ member.badge }}
            </li>
        </ul>
    </div>
</template>
