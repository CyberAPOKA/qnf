<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\MercadoPagoService;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollPendingPaymentsCommand extends Command
{
    protected $signature = 'futsal:poll-payments';

    protected $description = 'Consulta pagamentos pendentes no Mercado Pago e confirma os aprovados';

    public function handle(MercadoPagoService $mercadoPagoService, PaymentService $paymentService): int
    {
        $pending = Payment::whereNull('paid_at')
            ->whereNotNull('external_id')
            ->get();

        if ($pending->isEmpty()) {
            return self::SUCCESS;
        }

        $confirmed = 0;

        foreach ($pending as $payment) {
            $mpData = $mercadoPagoService->getPayment($payment->external_id);

            if (! $mpData) {
                continue;
            }

            if (($mpData['status'] ?? '') === 'approved') {
                $paymentService->confirmPayment($payment);
                $confirmed++;

                Log::info('Payment confirmed via polling', [
                    'payment_id' => $payment->id,
                    'mp_id' => $payment->external_id,
                ]);
            }
        }

        if ($confirmed > 0) {
            $this->info("Pagamentos confirmados: {$confirmed}");
        }

        return self::SUCCESS;
    }
}
