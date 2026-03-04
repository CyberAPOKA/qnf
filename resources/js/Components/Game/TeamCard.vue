<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Select from 'primevue/select';
import AddGuestModal from '@/Components/Game/AddGuestModal.vue';
import Button from 'primevue/button';

const props = defineProps({
    color: {
        type: String,
        required: true,
    },
    team: {
        type: Object,
        default: null,
    },
    editable: {
        type: Boolean,
        default: false,
    },
    gameId: {
        type: Number,
        default: null,
    },
    availablePlayers: {
        type: Array,
        default: () => [],
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

const teamSize = computed(() => members.value.length);

const goalkeeperCount = computed(() => {
    let count = 0;
    if (props.team?.captain?.position === 'goalkeeper') count++;
    for (const p of props.team?.players || []) {
        if (p.position === 'goalkeeper') count++;
    }
    return count;
});

const lineCount = computed(() => {
    let count = 0;
    if (props.team?.captain && props.team.captain.position !== 'goalkeeper') count++;
    for (const p of props.team?.players || []) {
        if (p.position !== 'goalkeeper') count++;
    }
    return count;
});

const needsGoalkeeper = computed(() => goalkeeperCount.value < 1);
const needsLine = computed(() => lineCount.value < 4);
const isFull = computed(() => teamSize.value >= 5);

const filteredPlayers = computed(() => {
    if (isFull.value) return [];
    return props.availablePlayers.filter((p) => {
        if (p.position === 'goalkeeper') return needsGoalkeeper.value;
        return needsLine.value;
    });
});

const selectedPlayer = ref(null);
const removeForm = useForm({ user_id: null, color: '' });
const addForm = useForm({ user_id: '', color: '' });
const guestModal = ref(null);

const allowedGuestPositions = computed(() => {
    if (isFull.value) return [];
    const positions = [];
    if (needsGoalkeeper.value) positions.push('goalkeeper');
    if (needsLine.value) positions.push('fixed', 'winger', 'pivot');
    return positions;
});

const removeMember = (userId) => {
    if (!props.gameId) return;
    removeForm.user_id = userId;
    removeForm.color = props.color;
    removeForm.post(route('games.remove-from-team', props.gameId), {
        preserveScroll: true,
        preserveState: false,
    });
};

const addMember = () => {
    if (!props.gameId || !selectedPlayer.value) return;
    addForm.user_id = selectedPlayer.value.id;
    addForm.color = props.color;
    addForm.post(route('games.add-to-team', props.gameId), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => { selectedPlayer.value = null; },
    });
};
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
                <i v-if="member.badgeIcon" :class="[member.badgeIcon, member.badgeClass]"></i>
                <button v-if="editable" @click="removeMember(member.id)" :disabled="removeForm.processing"
                    class="ml-auto rounded p-1 text-red-500 hover:bg-red-100 hover:text-red-700 transition">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </li>
        </ul>

        <div v-if="editable && !isFull && filteredPlayers.length" class="mt-3 flex gap-2 items-center">
            <Select v-model="selectedPlayer" :options="filteredPlayers" optionLabel="name"
                placeholder="Adicionar jogador..." filter class="flex-1">
                <template #option="{ option }">
                    <div class="flex items-center gap-2">
                        <span>{{ option.name }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700">
                            {{ option.position_label }}
                        </span>
                    </div>
                </template>
            </Select>
            <button @click="addMember" :disabled="addForm.processing || !selectedPlayer"
                class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold shadow-sm border border-gray-300 hover:bg-gray-50 disabled:opacity-50">
                <i class="fa-solid fa-plus"></i>
            </button>
        </div>

        <Button v-if="editable && !isFull && allowedGuestPositions.length" @click="guestModal?.open(color)"
            class="w-full mt-2" severity="contrast">
            <i class="fa-solid fa-user-plus mr-1"></i>
            Criar convidado
        </Button>

        <AddGuestModal ref="guestModal" :game-id="gameId" team-mode :allowed-positions="allowedGuestPositions" />
    </div>
</template>
