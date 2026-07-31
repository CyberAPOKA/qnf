<script setup>
import { ref } from 'vue';
import axios from 'axios';
import ScoreEntryCard from '@/Components/Game/ScoreEntryCard.vue';
import PaymentManagementCard from '@/Components/Game/PaymentManagementCard.vue';
import { useClipboard } from '@/composables/useClipboard';

const props = defineProps({
    game: {
        type: Object,
        default: null,
    },
    payments: {
        type: Array,
        default: () => [],
    },
    isCurrentRound: {
        type: Boolean,
        default: false,
    },
});

const { label: copyTeamsLabel, copy } = useClipboard('Copiar Times');
const copyTeams = () => copy(props.game?.whatsapp_message);

const paymentsLoading = ref(false);
const paymentsResult = ref(null);

const createPayments = async () => {
    if (paymentsLoading.value) return;
    paymentsLoading.value = true;
    paymentsResult.value = null;
    try {
        const { data } = await axios.post(route('api.payments.create-all'));
        paymentsResult.value = data.message;
    } catch (e) {
        paymentsResult.value = e.response?.data?.error || 'Erro ao criar pagamentos';
    } finally {
        paymentsLoading.value = false;
        setTimeout(() => { paymentsResult.value = null; }, 4000);
    }
};
</script>

<template>
    <div class="space-y-4">
        <button v-if="game?.whatsapp_message" type="button"
            class="w-full rounded-md border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50"
            @click="copyTeams">
            <i class="fa-regular fa-copy mr-1.5" aria-hidden="true" />
            {{ copyTeamsLabel }}
        </button>

        <ScoreEntryCard v-if="isCurrentRound && game?.id" :game-id="game.id" :teams="game.teams" />

        <PaymentManagementCard :payments="payments || []" />

        <div v-if="isCurrentRound" class="flex flex-col items-center gap-2">
            <button type="button" :disabled="paymentsLoading"
                class="w-full rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50"
                @click="createPayments">
                <i class="fa-solid fa-credit-card mr-1.5" aria-hidden="true" />
                {{ paymentsLoading ? 'Criando...' : 'Criar Pagamentos' }}
            </button>
            <p v-if="paymentsResult" class="text-sm font-medium text-gray-700">{{ paymentsResult }}</p>
        </div>
    </div>
</template>
