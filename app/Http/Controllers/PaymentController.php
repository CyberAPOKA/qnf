<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Admin confirma pagamento de um jogador.
     */
    public function confirm(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $this->paymentService->confirmPayment($payment);

        return back();
    }
}
