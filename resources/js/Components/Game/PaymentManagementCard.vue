<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
    payments: {
        type: Array,
        default: () => [],
    },
});

const formatAmount = (cents) => {
    return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
};

const confirmPayment = (paymentId) => {
    router.post(route('payments.confirm', paymentId), {}, {
        preserveScroll: true,
        preserveState: false,
    });
};

const paidCount = () => props.payments.filter(p => p.paid_at).length;
const unpaidCount = () => props.payments.filter(p => !p.paid_at).length;
</script>

<template>
    <div v-if="payments.length" class="rounded-xl bg-white p-2 lg:p-4 shadow">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900">
                <i class="fa-brands fa-pix text-[#32BCAD] mr-1"></i>
                Pagamentos
            </h3>
            <div class="flex gap-2 text-xs">
                <span class="rounded-full bg-green-100 px-2 py-1 text-green-700 font-semibold">
                    {{ paidCount() }} pagos
                </span>
                <span v-if="unpaidCount() > 0" class="rounded-full bg-red-100 px-2 py-1 text-red-700 font-semibold">
                    {{ unpaidCount() }} pendentes
                </span>
            </div>
        </div>

        <div class="space-y-2">
            <div v-for="payment in payments" :key="payment.id"
                class="flex items-center justify-between rounded-lg border p-2"
                :class="payment.paid_at ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'">

                <div class="flex items-center gap-2">
                    <i v-if="payment.paid_at" class="fa-solid fa-circle-check text-green-600"></i>
                    <i v-else class="fa-solid fa-clock text-red-500"></i>
                    <span class="font-medium text-gray-900 text-sm">{{ payment.user_name }}</span>
                    <span class="text-xs text-gray-500">{{ formatAmount(payment.amount) }}</span>
                    <span v-if="payment.penalty_rounds > 0 && !payment.paid_at"
                        class="rounded bg-red-200 px-1.5 py-0.5 text-xs text-red-800 font-semibold">
                        {{ payment.penalty_rounds >= 3 ? 'Suspenso' : payment.penalty_rounds + 'r' }}
                    </span>
                </div>

                <button v-if="!payment.paid_at" @click="confirmPayment(payment.id)"
                    class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 transition">
                    Confirmar
                </button>
                <span v-else class="text-xs text-green-700">Pago</span>
            </div>
        </div>
    </div>
</template>
