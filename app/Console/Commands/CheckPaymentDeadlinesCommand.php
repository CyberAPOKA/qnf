<?php

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

class CheckPaymentDeadlinesCommand extends Command
{
    protected $signature = 'futsal:check-payment-deadlines';

    protected $description = 'Verifica prazos de pagamento e aplica suspensões';

    public function handle(PaymentService $paymentService): int
    {
        $affected = $paymentService->checkDeadlinesAndSuspend();

        if ($affected > 0) {
            $this->info("Suspensões aplicadas: {$affected}");
        } else {
            $this->line('Sem alterações.');
        }

        return self::SUCCESS;
    }
}
