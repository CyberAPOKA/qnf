<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import GameStatusCard from '@/Components/Game/GameStatusCard.vue';
import PlayerListCard from '@/Components/Game/PlayerListCard.vue';
import TeamCard from '@/Components/Game/TeamCard.vue';
import WhatsAppCard from '@/Components/Game/WhatsAppCard.vue';
import RankingCard from '@/Components/Game/RankingCard.vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import { useGameChannel } from '@/composables/useGameChannel';
import { useDraftRedirect } from '@/composables/useDraftRedirect';

const props = defineProps({
    game: Object,
    current_user_id: Number,
    is_goalkeeper: Boolean,
    ranking: Array,
});

const { store } = useGameChannel(props);
useDraftRedirect();
const form = useForm({});

const joined = computed(() => {
    return !!store.game?.players?.some((player) => player.id === props.current_user_id);
});

const linePlayerCount = computed(() => {
    return (store.game?.players || []).filter((p) => p.position !== 'goalkeeper').length;
});

const canJoin = computed(() => {
    if (props.is_goalkeeper) return false;
    return store.game?.status === 'open' && !joined.value && linePlayerCount.value < 12;
});

const joinGame = () => {
    if (!store.game) return;
    form.post(route('games.join', store.game.id), { preserveScroll: true, preserveState: false });
};

const countdown = ref('');
let countdownTimer = null;

const updateCountdown = () => {
    console.log('opens_at', store.game?.opens_at);

    const opensAt = store.game?.opens_at;
    if (!opensAt) { countdown.value = ''; return; }

    const diff = new Date(opensAt) - Date.now();
    if (diff <= 0) {
        countdown.value = '';
        clearInterval(countdownTimer);
        router.reload();
        return;
    }

    const days = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    const minutes = Math.floor((diff % 3600000) / 60000);
    const seconds = Math.floor((diff % 60000) / 1000);

    const parts = [];
    if (days > 0) parts.push(`${days}d`);
    if (hours > 0) parts.push(`${hours}h`);
    if (minutes > 0) parts.push(`${String(minutes).padStart(2, '0')}m`);
    if (minutes > 0 || hours > 0 || days > 0) {
        parts.push(`${String(seconds).padStart(2, '0')}s`);
    } else {
        parts.push(`${seconds}`);
    }
    countdown.value = parts.join(' ');
};

onMounted(() => {
    updateCountdown();
    countdownTimer = setInterval(updateCountdown, 1000);
});

onUnmounted(() => {
    clearInterval(countdownTimer);
});
</script>

<template>
    <AppLayout title="">
        <template #header>
            <h2 class="qnf-title font-semibold text-lg text-gray-800 leading-tight text-center">
                <i class="fa-solid fa-fire qnf-fire"></i>
                <span class="qnf-text">QUINTA NOBRE FUTSAL 2026</span>
                <i class="fa-solid fa-fire qnf-fire"></i>
            </h2>
        </template>

        <div class="p-2 lg:p-4">
            <div class="mx-auto max-w-xl space-y-4">
                <GameStatusCard v-if="store.game?.status !== 'done'" :status-label="store.game?.status_label"
                    :players-count="store.game?.players_count" :round="store.game?.round">
                    <template #actions>

                        <div v-if="store.game?.status === 'scheduled'" class="text-center">
                            <p class="font-bold text-lg text-gray-900">MERCADO EM</p>
                            <p v-if="countdown" class="text-2xl font-bold text-purple-600 tabular-nums">
                                {{ countdown }}
                            </p>
                        </div>

                        <PrimaryButton v-else v-if="!is_goalkeeper" class="w-full justify-center py-3 text-base"
                            :disabled="form.processing || !canJoin" @click="joinGame">
                            Eu quero jogar
                        </PrimaryButton>

                        <Link v-if="store.game?.status === 'drafting'"
                            class="inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-3 text-base font-semibold text-white hover:bg-indigo-700"
                            :href="route('games.draft', store.game.id)">
                            Ir para Draft
                        </Link>
                    </template>

                    <template #footer>
                        <p v-if="store.game?.status === 'full'" class="mt-3 text-sm font-medium text-red-600">
                            Lista fechada
                        </p>
                    </template>
                </GameStatusCard>

                <PlayerListCard v-if="store.game?.status !== 'done'" :players="store.game?.players || []" />

                <template v-if="store.game?.status === 'done'">
                    <div class="grid grid-cols-1 gap-3">
                        <TeamCard color="green" :team="store.game?.teams?.green" />
                        <TeamCard color="yellow" :team="store.game?.teams?.yellow" />
                        <TeamCard color="blue" :team="store.game?.teams?.blue" />
                    </div>
                    <WhatsAppCard :message="store.game?.whatsapp_message || ''" />
                </template>

                <RankingCard :ranking="ranking || []" />
            </div>
        </div>
    </AppLayout>
</template>

<style>
.qnf-title {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .6rem;
    padding: .35rem 1rem;
    border-radius: 999px;
    isolation: isolate;
    transform: translateZ(0);
}

.qnf-title::before {
    content: "";
    position: absolute;
    inset: -6px -10px;
    border-radius: 999px;
    background:
        radial-gradient(120px 40px at 12% 120%, rgba(255, 120, 0, .55), transparent 65%),
        radial-gradient(140px 50px at 88% 120%, rgba(255, 0, 0, .45), transparent 70%),
        radial-gradient(260px 80px at 50% 130%, rgba(255, 200, 0, .35), transparent 75%);
    filter: blur(10px);
    opacity: .9;
    z-index: -2;
    animation: qnfGlow 1.2s ease-in-out infinite alternate;
}

.qnf-text {
    position: relative;
    letter-spacing: .06em;
    text-shadow:
        0 0 10px rgba(255, 120, 0, .55),
        0 0 24px rgba(255, 0, 0, .35);
    animation: qnfPulse .9s ease-in-out infinite alternate;
}

.qnf-text::before {
    content: "";
    position: absolute;
    left: -10%;
    right: -10%;
    bottom: -8px;
    height: 10px;
    border-radius: 999px;
    background:
        radial-gradient(closest-side, rgba(255, 200, 0, .85), rgba(255, 80, 0, .55), transparent 70%);
    filter: blur(6px);
    opacity: .85;
    z-index: -1;
    animation: qnfHeat 1.1s ease-in-out infinite;
}

.qnf-fire {
    color: #ff3b30;
    filter: drop-shadow(0 0 10px rgba(255, 90, 0, .75));
    animation: qnfFlame .55s ease-in-out infinite alternate;
}

.qnf-fire:first-child {
    animation-delay: .08s;
}

.qnf-fire:last-child {
    animation-delay: .22s;
}

@keyframes qnfFlame {
    0% {
        transform: translateY(1px) scale(.98) rotate(-6deg);
        filter: drop-shadow(0 0 8px rgba(255, 110, 0, .65));
    }

    100% {
        transform: translateY(-2px) scale(1.08) rotate(6deg);
        filter: drop-shadow(0 0 14px rgba(255, 180, 0, .85));
    }
}

@keyframes qnfGlow {
    0% {
        transform: scale(.98);
        opacity: .75;
    }

    100% {
        transform: scale(1.03);
        opacity: 1;
    }
}

@keyframes qnfPulse {
    0% {
        transform: translateY(0);
    }

    100% {
        transform: translateY(-1px);
    }
}

@keyframes qnfHeat {

    0%,
    100% {
        transform: translateY(0) scaleX(1);
        opacity: .75;
    }

    50% {
        transform: translateY(-2px) scaleX(1.08);
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {

    .qnf-title::before,
    .qnf-text,
    .qnf-text::before,
    .qnf-fire {
        animation: none !important;
    }
}
</style>