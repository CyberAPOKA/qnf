<script setup>
import { useClipboard } from '@/composables/useClipboard';

const props = defineProps({
    payment: {
        type: Object,
        required: true,
    },
});

const { label: copyLabel, copy } = useClipboard();

const copyPixCode = () => copy(props.payment.pix_payload);

const formatAmount = (cents) => {
    return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
};
</script>

<template>
    <div class="rounded-xl bg-white p-4 shadow">
        <!-- Pago -->
        <div v-if="payment.paid_at" class="text-center space-y-2">
            <div class="inline-flex items-center gap-2 rounded-full bg-green-100 px-4 py-2">
                <i class="fa-solid fa-circle-check text-green-600 text-xl"></i>
                <span class="font-semibold text-green-800">Pagamento confirmado!</span>
            </div>
            <p class="text-sm text-gray-500">{{ formatAmount(payment.amount) }}</p>
        </div>

        <!-- Pendente -->
        <div v-else class="space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-semibold text-gray-900">
                    <i class="fa-brands fa-pix text-[#32BCAD] mr-1"></i>
                    Pagamento via Pix
                </h3>
                <span class="rounded-full bg-yellow-100 px-3 py-1 text-xs font-semibold text-yellow-800">
                    Pendente
                </span>
            </div>

            <p class="text-2xl font-bold text-gray-900 text-center">{{ formatAmount(payment.amount) }}</p>

            <div v-if="payment.penalty_rounds > 0"
                class="rounded-lg bg-red-50 border border-red-200 p-3 text-center">
                <p class="text-sm font-semibold text-red-700">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                    <template v-if="payment.penalty_rounds >= 3">
                        Suspenso até efetuar o pagamento + 3 rodadas
                    </template>
                    <template v-else>
                        Suspensão de {{ payment.penalty_rounds }} rodada{{ payment.penalty_rounds > 1 ? 's' : '' }} aplicada
                    </template>
                </p>
            </div>

            <div v-if="payment.qr_code_base64" class="flex justify-center">
                <img :src="'data:image/png;base64,' + payment.qr_code_base64" alt="QR Code Pix" class="rounded-lg w-[280px]" />
            </div>

            <div class="space-y-2">
                <p class="text-xs text-gray-500 text-center">Ou copie o código Pix:</p>
                <div class="flex gap-2">
                    <input type="text" :value="payment.pix_payload" readonly
                        class="flex-1 rounded-lg border-gray-300 bg-gray-50 text-xs font-mono truncate" />
                    <button @click="copyPixCode"
                        class="shrink-0 rounded-lg bg-[#32BCAD] px-4 py-2 text-sm font-semibold text-white hover:bg-[#2aa89b] transition">
                        <i class="fa-solid fa-copy mr-1"></i>
                        {{ copyLabel }}
                    </button>
                </div>
            </div>

            <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 space-y-1">
                <p class="font-semibold text-gray-700">Prazos de pagamento:</p>
                <p><i class="fa-solid fa-clock text-yellow-500 mr-1"></i> Até sábado 00:15 — sem penalidade</p>
                <p><i class="fa-solid fa-clock text-orange-500 mr-1"></i> Até domingo 00:15 — 1 rodada de suspensão</p>
                <p><i class="fa-solid fa-clock text-red-500 mr-1"></i> Até segunda 00:15 — 2 rodadas de suspensão</p>
                <p><i class="fa-solid fa-ban text-red-700 mr-1"></i> Após segunda — suspenso até pagar + 3 rodadas</p>
            </div>
        </div>
    </div>
</template>
