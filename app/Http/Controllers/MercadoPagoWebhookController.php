<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\MercadoPagoService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mercadoPagoService,
        private readonly PaymentService $paymentService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $type = $request->input('type') ?? $request->input('topic');

        if ($type !== 'payment') {
            return response()->json(['status' => 'ignored']);
        }

        $mpPaymentId = $request->input('data.id') ?? $request->input('id');

        if (! $mpPaymentId) {
            return response()->json(['status' => 'no_id'], 400);
        }

        $mpData = $this->mercadoPagoService->getPayment($mpPaymentId);

        if (! $mpData) {
            Log::warning('MP webhook: could not fetch payment', ['mp_id' => $mpPaymentId]);

            return response()->json(['status' => 'fetch_failed'], 404);
        }

        $status = $mpData['status'] ?? '';

        if ($status !== 'approved') {
            Log::info('MP webhook: payment not approved', [
                'mp_id' => $mpPaymentId,
                'status' => $status,
            ]);

            return response()->json(['status' => 'not_approved']);
        }

        // Match by external_id (MP payment ID stored in our DB)
        $payment = Payment::where('external_id', (string) $mpPaymentId)->first();

        // Fallback: match by external_reference
        if (! $payment && isset($mpData['external_reference'])) {
            $ref = $mpData['external_reference'];
            // Format: QNF-G{game_id}-U{user_id}
            if (preg_match('/^QNF-G(\d+)-U(\d+)$/', $ref, $matches)) {
                $payment = Payment::where('game_id', (int) $matches[1])
                    ->where('user_id', (int) $matches[2])
                    ->first();
            }
        }

        if (! $payment) {
            Log::warning('MP webhook: no matching payment found', [
                'mp_id' => $mpPaymentId,
                'external_reference' => $mpData['external_reference'] ?? null,
            ]);

            return response()->json(['status' => 'not_found'], 404);
        }

        if ($payment->isPaid()) {
            return response()->json(['status' => 'already_paid']);
        }

        $this->paymentService->confirmPayment($payment);

        Log::info('MP webhook: payment confirmed', [
            'payment_id' => $payment->id,
            'mp_id' => $mpPaymentId,
        ]);

        return response()->json(['status' => 'confirmed']);
    }
}
