<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Jogador solicita/garante a cobrança Pix da rodada, enviando Device ID quando disponível.
     */
    public function ensure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'device_id' => ['nullable', 'string', 'max:2048'],
        ]);

        $game = Game::findOrFail($validated['game_id']);
        $deviceId = filled($validated['device_id'] ?? null) ? $validated['device_id'] : null;

        try {
            $payment = $this->paymentService->ensurePaymentForPlayer(
                $game,
                $request->user(),
                $deviceId,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        if (! $payment) {
            return response()->json(['message' => 'Pagamento Pix não disponível para este jogador.'], 422);
        }

        return response()->json([
            'payment' => $this->paymentService->playerPayload($payment),
        ]);
    }

    /**
     * Admin confirma pagamento de um jogador.
     */
    public function confirm(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $this->paymentService->confirmPayment($payment, Payment::METHOD_MANUAL);

        return back();
    }
}
