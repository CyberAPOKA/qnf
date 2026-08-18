<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Select from 'primevue/select';
import AddGuestModal from '@/Components/Game/AddGuestModal.vue';
import Button from 'primevue/button';
import DialogModal from '@/Components/DialogModal.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

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
    winner: {
        type: Boolean,
        default: false,
    },
});

const colorConfig = {
    green: { label: 'Time Verde' },
    yellow: { label: 'Time Amarelo' },
    blue: { label: 'Time Azul' },
};

const config = computed(() => colorConfig[props.color] || colorConfig.green);

const members = computed(() => {
    const list = [];

    if (props.team?.captain) {
        list.push({
            id: props.team.captain.id,
            name: props.team.captain.name,
            position: props.team.captain.position,
            badgeIcon: 'fa-solid fa-copyright',
            badgeTitle: 'Capitão',
        });
    }

    for (const player of props.team?.players || []) {
        let badgeIcon = '';
        let badgeTitle = '';
        if (player.position === 'goalkeeper') {
            badgeIcon = 'fa-solid fa-mitten';
            badgeTitle = 'Goleiro';
        } else if (player.is_first_pick) {
            badgeIcon = 'fa-solid fa-1';
            badgeTitle = 'Primeira escolha';
        }
        list.push({
            id: player.id,
            name: player.name,
            position: player.position,
            badgeIcon,
            badgeTitle,
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
const replaceForm = useForm({ user_id: null, replacement_user_id: null, color: '' });
const guestModal = ref(null);
const memberToRemove = ref(null);
const memberToReplace = ref(null);
const replacementPlayer = ref(null);

const isGoalkeeper = (member) => member?.position === 'goalkeeper';

const replaceCandidates = computed(() => {
    if (!memberToReplace.value) return [];
    const wantGoalkeeper = isGoalkeeper(memberToReplace.value);
    return props.availablePlayers.filter((p) => (p.position === 'goalkeeper') === wantGoalkeeper);
});

const allowedGuestPositions = computed(() => {
    if (isFull.value) return [];
    const positions = [];
    if (needsGoalkeeper.value) positions.push('goalkeeper');
    if (needsLine.value) positions.push('fixed', 'winger', 'pivot');
    return positions;
});

const openRemoveModal = (member) => {
    memberToRemove.value = member;
};

const closeRemoveModal = () => {
    if (removeForm.processing) return;
    memberToRemove.value = null;
};

const confirmRemove = () => {
    if (!props.gameId || !memberToRemove.value) return;
    removeForm.user_id = memberToRemove.value.id;
    removeForm.color = props.color;
    removeForm.post(route('games.remove-from-team', props.gameId), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => { memberToRemove.value = null; },
    });
};

const openReplaceModal = (member) => {
    memberToReplace.value = member;
    replacementPlayer.value = null;
    replaceForm.reset();
    replaceForm.clearErrors();
};

const closeReplaceModal = () => {
    if (replaceForm.processing) return;
    memberToReplace.value = null;
    replacementPlayer.value = null;
};

const confirmReplace = () => {
    if (!props.gameId || !memberToReplace.value || !replacementPlayer.value) return;
    replaceForm.user_id = memberToReplace.value.id;
    replaceForm.replacement_user_id = replacementPlayer.value.id;
    replaceForm.color = props.color;
    replaceForm.post(route('games.replace-in-team', props.gameId), {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            memberToReplace.value = null;
            replacementPlayer.value = null;
        },
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

const firstName = (name) => name.split(' ')[0];
</script>

<template>
    <article
        class="team-card"
        :class="[`team-card--${color}`, { 'team-card--winner': winner }]"
    >
        <span class="team-card__corner team-card__corner--tl" aria-hidden="true" />
        <span class="team-card__corner team-card__corner--tr" aria-hidden="true" />
        <span class="team-card__corner team-card__corner--bl" aria-hidden="true" />
        <span class="team-card__corner team-card__corner--br" aria-hidden="true" />

        <div v-if="winner" class="team-card__ribbon">
            <i class="fa-solid fa-trophy" aria-hidden="true" />
            <span>Vencedor</span>
        </div>

        <header class="team-card__head">
            <span class="team-card__crest" aria-hidden="true">
                <i class="fa-solid fa-futbol" />
            </span>
            <p class="team-card__name">{{ config.label }}</p>
            <span v-if="team?.score != null" class="team-card__score">{{ team.score }}</span>
        </header>

        <ul class="team-card__list">
            <li v-for="member in members" :key="member.id" class="team-card__row">
                <span class="team-card__dot" aria-hidden="true" />
                <span class="team-card__player">{{ firstName(member.name) }}</span>
                <span
                    v-if="member.badgeIcon"
                    class="team-card__badge"
                    :title="member.badgeTitle"
                >
                    <i :class="member.badgeIcon" aria-hidden="true" />
                </span>
                <span v-if="editable" class="team-card__actions">
                    <button
                        type="button"
                        class="team-card__swap"
                        title="Substituir"
                        :disabled="replaceForm.processing"
                        @click="openReplaceModal(member)"
                    >
                        <i class="fa-solid fa-right-left" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        class="team-card__remove"
                        title="Remover"
                        :disabled="removeForm.processing"
                        @click="openRemoveModal(member)"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true" />
                    </button>
                </span>
            </li>
        </ul>

        <div v-if="editable && !isFull && filteredPlayers.length" class="team-card__editor">
            <Select
                v-model="selectedPlayer"
                :options="filteredPlayers"
                option-label="name"
                placeholder="Adicionar..."
                filter
                class="flex-1"
            >
                <template #option="{ option }">
                    <div class="flex items-center gap-2">
                        <span>{{ option.name }}</span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700">
                            {{ option.position_label }}
                        </span>
                    </div>
                </template>
            </Select>
            <button
                type="button"
                class="team-card__add"
                :disabled="addForm.processing || !selectedPlayer"
                @click="addMember"
            >
                <i class="fa-solid fa-plus" aria-hidden="true" />
            </button>
        </div>

        <Button
            v-if="editable && !isFull && allowedGuestPositions.length"
            class="mt-2 w-full"
            severity="contrast"
            @click="guestModal?.open(color)"
        >
            <i class="fa-solid fa-user-plus mr-1" aria-hidden="true" />
            Criar convidado
        </Button>

        <AddGuestModal ref="guestModal" :game-id="gameId" team-mode :allowed-positions="allowedGuestPositions" />

        <ConfirmationModal :show="memberToRemove !== null" max-width="lg" @close="closeRemoveModal">
            <template #title>Remover do time</template>
            <template #content>
                Tem certeza que deseja remover
                <strong>{{ memberToRemove ? firstName(memberToRemove.name) : '' }}</strong>
                do {{ config.label }}? O jogador será marcado como desistente nesta rodada.
            </template>
            <template #footer>
                <SecondaryButton :disabled="removeForm.processing" @click="closeRemoveModal">Cancelar</SecondaryButton>
                <PrimaryButton
                    class="ms-3 !bg-red-600 hover:!bg-red-500"
                    :disabled="removeForm.processing"
                    @click="confirmRemove"
                >
                    {{ removeForm.processing ? 'Removendo...' : 'Sim, remover' }}
                </PrimaryButton>
            </template>
        </ConfirmationModal>

        <DialogModal :show="memberToReplace !== null" max-width="lg" @close="closeReplaceModal">
            <template #title>Substituir jogador</template>
            <template #content>
                <p class="mb-3">
                    Escolha quem vai substituir
                    <strong>{{ memberToReplace ? firstName(memberToReplace.name) : '' }}</strong>
                    ({{ memberToReplace && isGoalkeeper(memberToReplace) ? 'goleiro' : 'linha' }}).
                </p>
                <div
                    v-if="replaceCandidates.length"
                    class="max-h-64 overflow-y-auto rounded-md border border-gray-200"
                >
                    <button
                        v-for="player in replaceCandidates"
                        :key="player.id"
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition hover:bg-gray-50"
                        :class="replacementPlayer?.id === player.id ? 'bg-indigo-50 text-indigo-900' : 'text-gray-800'"
                        @click="replacementPlayer = player"
                    >
                        <span class="font-medium">{{ player.name }}</span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700">
                            {{ player.position_label }}
                        </span>
                    </button>
                </div>
                <p v-else class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-500">
                    Nenhum {{ memberToReplace && isGoalkeeper(memberToReplace) ? 'goleiro' : 'jogador de linha' }} disponível para substituição.
                </p>
                <InputError :message="replaceForm.errors.replacement_user_id" class="mt-2" />
                <InputError :message="replaceForm.errors.user_id" class="mt-2" />
            </template>
            <template #footer>
                <SecondaryButton :disabled="replaceForm.processing" @click="closeReplaceModal">Cancelar</SecondaryButton>
                <PrimaryButton
                    class="ms-3"
                    :disabled="replaceForm.processing || !replacementPlayer"
                    @click="confirmReplace"
                >
                    {{ replaceForm.processing ? 'Substituindo...' : 'Substituir' }}
                </PrimaryButton>
            </template>
        </DialogModal>
    </article>
</template>

<style scoped>
.team-card {
    --team: #22c55e;
    --team-rgb: 34, 197, 94;
    --team-light: #86efac;
    --cut: 12px;

    position: relative;
    padding: 10px 8px 12px;
    background:
        radial-gradient(120% 80% at 50% -10%, rgba(var(--team-rgb), 0.22), transparent 60%),
        linear-gradient(180deg, rgba(10, 16, 28, 0.96) 0%, rgba(6, 10, 20, 0.98) 100%);
    box-shadow:
        inset 0 0 0 1.5px rgba(var(--team-rgb), 0.55),
        inset 0 0 26px rgba(var(--team-rgb), 0.12),
        0 0 16px rgba(var(--team-rgb), 0.18);
    clip-path: polygon(
        var(--cut) 0,
        calc(100% - var(--cut)) 0,
        100% var(--cut),
        100% calc(100% - var(--cut)),
        calc(100% - var(--cut)) 100%,
        var(--cut) 100%,
        0 calc(100% - var(--cut)),
        0 var(--cut)
    );
    transition: box-shadow 200ms ease;
}

.team-card--green {
    --team: #22c55e;
    --team-rgb: 34, 197, 94;
    --team-light: #86efac;
}

.team-card--yellow {
    --team: #eab308;
    --team-rgb: 234, 179, 8;
    --team-light: #fde68a;
}

.team-card--blue {
    --team: #3b82f6;
    --team-rgb: 59, 130, 246;
    --team-light: #93c5fd;
}

.team-card--winner {
    box-shadow:
        inset 0 0 0 2px rgba(var(--team-rgb), 0.95),
        inset 0 0 34px rgba(var(--team-rgb), 0.2),
        0 0 22px rgba(var(--team-rgb), 0.55),
        0 0 46px rgba(var(--team-rgb), 0.28);
}

/* Cantos em L */
.team-card__corner {
    position: absolute;
    width: 14px;
    height: 14px;
    border: 2px solid var(--team);
    opacity: 0.9;
    pointer-events: none;
}

.team-card__corner--tl {
    top: 5px;
    left: 5px;
    border-right: 0;
    border-bottom: 0;
}

.team-card__corner--tr {
    top: 5px;
    right: 5px;
    border-bottom: 0;
    border-left: 0;
}

.team-card__corner--bl {
    bottom: 5px;
    left: 5px;
    border-top: 0;
    border-right: 0;
}

.team-card__corner--br {
    right: 5px;
    bottom: 5px;
    border-top: 0;
    border-left: 0;
}

/* Faixa de vencedor */
.team-card__ribbon {
    position: absolute;
    top: 0;
    left: 50%;
    z-index: 3;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 14px 4px;
    transform: translateX(-50%);
    background: linear-gradient(180deg, rgba(var(--team-rgb), 0.95), rgba(var(--team-rgb), 0.6));
    color: #04140a;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    white-space: nowrap;
    clip-path: polygon(8px 0, calc(100% - 8px) 0, 100% 100%, 0 100%);
    box-shadow: 0 0 14px rgba(var(--team-rgb), 0.7);
}

.team-card--winner .team-card__head {
    padding-top: 14px;
}

/* Cabeçalho */
.team-card__head {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0 2px 8px;
    border-bottom: 1px solid rgba(var(--team-rgb), 0.3);
}

.team-card__crest {
    display: none;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 30px;
    flex: 0 0 auto;
    color: var(--team-light);
    font-size: 0.8rem;
    background: linear-gradient(180deg, rgba(var(--team-rgb), 0.35), rgba(var(--team-rgb), 0.1));
    box-shadow: inset 0 0 0 1px rgba(var(--team-rgb), 0.6);
    clip-path: polygon(50% 0, 100% 22%, 100% 74%, 50% 100%, 0 74%, 0 22%);
}

.team-card__name {
    flex: 1 1 auto;
    min-width: 0;
    margin: 0;
    color: #f1f5f9;
    font-family: var(--font-display, 'Rajdhani', sans-serif);
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    line-height: 1.1;
}

.team-card__score {
    font-family: var(--font-special, 'Orbitron', sans-serif);
    font-size: 1.35rem;
    font-weight: 800;
    line-height: 1;
    color: #fff;
    text-shadow:
        0 0 8px rgba(var(--team-rgb), 0.9),
        0 0 18px rgba(var(--team-rgb), 0.55);
}

/* Jogadores */
.team-card__list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 8px 0 0;
    padding: 0;
    list-style: none;
}

.team-card__row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 2px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.07);
}

.team-card__row:last-child {
    border-bottom: 0;
}

.team-card__dot {
    flex: 0 0 auto;
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: var(--team);
    box-shadow: 0 0 6px rgba(var(--team-rgb), 0.9);
}

.team-card__player {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    color: #e2e8f0;
    font-size: 0.78rem;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.team-card__badge {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    color: var(--team-light);
    font-size: 0.72rem;
    opacity: 0.9;
}

.team-card__actions {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 4px;
}

.team-card__swap {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    color: #38bdf8;
    font-size: 0.58rem;
    background: rgba(56, 189, 248, 0.12);
    border-radius: 4px;
    transition: background 150ms ease, color 150ms ease;
}

.team-card__swap:hover:not(:disabled) {
    background: rgba(56, 189, 248, 0.28);
    color: #bae6fd;
}

.team-card__swap:disabled {
    opacity: 0.5;
}

.team-card__remove {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    color: #f87171;
    font-size: 0.7rem;
    background: rgba(248, 113, 113, 0.12);
    border-radius: 4px;
    transition: background 150ms ease, color 150ms ease;
}

.team-card__remove:hover:not(:disabled) {
    background: rgba(248, 113, 113, 0.28);
    color: #fecaca;
}

.team-card__remove:disabled {
    opacity: 0.5;
}

/* Edição (admin) */
.team-card__editor {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
}

.team-card__add {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    flex: 0 0 auto;
    color: var(--team-light);
    background: rgba(var(--team-rgb), 0.15);
    box-shadow: inset 0 0 0 1px rgba(var(--team-rgb), 0.5);
    border-radius: 6px;
    transition: background 150ms ease;
}

.team-card__add:hover:not(:disabled) {
    background: rgba(var(--team-rgb), 0.3);
}

.team-card__add:disabled {
    opacity: 0.45;
}

@media (min-width: 1024px) {
    .team-card {
        --cut: 16px;
        padding: 14px 12px 16px;
    }

    .team-card__crest {
        display: inline-flex;
    }

    .team-card__ribbon {
        font-size: 0.68rem;
        padding: 4px 18px 5px;
    }

    .team-card__name {
        font-size: 1.05rem;
    }

    .team-card__score {
        font-size: 2rem;
    }

    .team-card__player {
        font-size: 0.95rem;
    }

    .team-card__badge {
        font-size: 0.85rem;
    }

    .team-card__row {
        gap: 8px;
        padding: 5px 2px;
    }

    .team-card__dot {
        width: 8px;
        height: 8px;
    }
}
</style>
