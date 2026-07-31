<script setup>
import { Link } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FuturisticButton from '@/Components/FuturisticButton.vue';
import Button from 'primevue/button';

defineProps({
    game: {
        type: Object,
        default: null,
    },
    isCurrentRound: {
        type: Boolean,
        default: false,
    },
    isGoalkeeper: {
        type: Boolean,
        default: false,
    },
    droppedOut: {
        type: Boolean,
        default: false,
    },
    waitlistPosition: {
        type: Number,
        default: null,
    },
    canJoin: {
        type: Boolean,
        default: false,
    },
    canJoinWaitlist: {
        type: Boolean,
        default: false,
    },
    canQuit: {
        type: Boolean,
        default: false,
    },
    joining: {
        type: Boolean,
        default: false,
    },
    joiningWaitlist: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['join', 'join-waitlist', 'quit']);
</script>

<template>
    <div class="flex flex-col gap-2">
        <template v-if="!isGoalkeeper && isCurrentRound">
            <p v-if="droppedOut" class="m-0 font-semibold text-red-400">
                Você desistiu desta rodada!
                <i class="fa-regular fa-face-sad-tear" aria-hidden="true" />
            </p>

            <p v-else-if="waitlistPosition" class="m-0 font-semibold text-amber-400">
                <i class="fa-solid fa-clock" aria-hidden="true" />
                Você está na fila de espera ({{ waitlistPosition }}º)
            </p>

            <template v-else>
                <FuturisticButton v-if="canJoin" label="Eu quero jogar" icon="fa-solid fa-futbol"
                    :disabled="joining" @click="$emit('join')" />

                <PrimaryButton v-if="canJoinWaitlist"
                    class="w-full justify-center py-3 text-base !bg-amber-500 hover:!bg-amber-600 focus:!bg-amber-600"
                    :disabled="joiningWaitlist" @click="$emit('join-waitlist')">
                    Entrar na fila de espera
                </PrimaryButton>

                <Button v-if="canQuit" severity="danger" fluid @click="$emit('quit')">
                    Eu quero desistir
                </Button>
            </template>
        </template>

        <Link v-if="game?.status === 'drafting' && isCurrentRound"
            class="inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-3 text-base font-semibold text-white transition hover:bg-indigo-700"
            :href="route('games.draft', game.id)">
            Ir para Draft
        </Link>

        <!-- <Link v-if="game?.status === 'drafted' && isCurrentRound" :href="route('games.rec', game.id)"
            class="block w-full overflow-hidden rounded-md shadow transition-opacity hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
            :style="{
                backgroundImage: 'url(/assets/rec/button.png)',
                backgroundSize: 'cover',
                backgroundPosition: 'center',
                aspectRatio: '401 / 137',
            }" aria-label="Modo REC">
            <span class="sr-only">Modo REC</span>
        </Link> -->
    </div>
</template>
