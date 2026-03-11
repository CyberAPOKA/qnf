<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Models\Game;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public const TZ = 'America/Sao_Paulo';

    public function __construct(
        private readonly MercadoPagoService $mercadoPagoService,
    ) {}

    /**
     * Cria cobrança Pix para um jogador específico.
     */
    public function createPaymentForPlayer(Game $game, User $player): void
    {
        if ($player->position === Position::GOALKEEPER || $player->guest) {
            return;
        }

        $existing = Payment::where('game_id', $game->id)
            ->where('user_id', $player->id)
            ->first();

        if ($existing) {
            return;
        }

        $amount = (int) config('services.pix.amount', 800);
        $externalRef = "QNF-G{$game->id}-U{$player->id}";

        $mpData = $this->mercadoPagoService->createPixPayment(
            $amount,
            "QNF Futsal - Rodada {$game->round}",
            $externalRef,
            $player->email,
        );

        Payment::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'amount' => $amount,
            'pix_payload' => $mpData['qr_code'],
            'external_id' => (string) $mpData['id'],
            'qr_code_base64' => $mpData['qr_code_base64'],
        ]);
    }

    /**
     * Cancela o pagamento pendente de um jogador e remove o registro local.
     */
    public function cancelPaymentForPlayer(int $gameId, int $userId): void
    {
        $payment = Payment::where('game_id', $gameId)
            ->where('user_id', $userId)
            ->first();

        if (! $payment) {
            return;
        }

        if ($payment->isPaid()) {
            return;
        }

        if ($payment->external_id) {
            rescue(fn () => $this->mercadoPagoService->cancelPayment($payment->external_id), report: false);
        }

        $payment->delete();
    }

    /**
     * Admin confirma pagamento de um jogador.
     */
    public function confirmPayment(Payment $payment): void
    {
        if ($payment->isPaid()) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            $payment->update(['paid_at' => now()]);

            $user = $payment->user;

            // Se estava em suspensão permanente (penalty_rounds == 3), aplica 3 rodadas a partir do jogo atual
            if ($payment->penalty_rounds >= 3) {
                $latestRound = Game::where('status', GameStatus::DONE->value)
                    ->orderByDesc('date')
                    ->value('round') ?? $payment->game->round;

                $user->update(['suspended_until_round' => $latestRound + 3]);
            } else {
                // Verifica se o jogador não tem outras pendências antes de limpar suspensão
                $hasOtherUnpaid = Payment::where('user_id', $user->id)
                    ->where('id', '!=', $payment->id)
                    ->whereNull('paid_at')
                    ->where('penalty_rounds', '>=', 3)
                    ->exists();

                if (! $hasOtherUnpaid && $user->suspended_until_round === 0) {
                    $user->update(['suspended_until_round' => null]);
                }
            }
        });
    }

    /**
     * Verifica prazos de pagamento e aplica suspensões.
     * Executado pelo scheduler a cada minuto.
     */
    public function checkDeadlinesAndSuspend(): int
    {
        $now = CarbonImmutable::now(self::TZ);
        $affected = 0;

        $unpaidPayments = Payment::whereNull('paid_at')
            ->whereHas('game', fn ($q) => $q->whereIn('status', [GameStatus::DRAFTED->value, GameStatus::DONE->value]))
            ->with('game', 'user')
            ->get();

        foreach ($unpaidPayments as $payment) {
            $gameDate = CarbonImmutable::instance($payment->game->date)->setTimezone(self::TZ);

            $saturdayDeadline = $gameDate->addDays(2)->setTime(0, 15);
            $sundayDeadline = $gameDate->addDays(3)->setTime(0, 15);
            $mondayDeadline = $gameDate->addDays(4)->setTime(0, 15);

            $newPenalty = 0;
            if ($now->gte($mondayDeadline)) {
                $newPenalty = 3;
            } elseif ($now->gte($sundayDeadline)) {
                $newPenalty = 2;
            } elseif ($now->gte($saturdayDeadline)) {
                $newPenalty = 1;
            }

            if ($newPenalty <= $payment->penalty_rounds) {
                continue;
            }

            $payment->update(['penalty_rounds' => $newPenalty]);
            $user = $payment->user;
            $gameRound = $payment->game->round;

            if ($newPenalty >= 3) {
                $user->update(['suspended_until_round' => 0]);
            } else {
                $targetRound = $gameRound + $newPenalty;
                if ($user->suspended_until_round === null || ($user->suspended_until_round !== 0 && $targetRound > $user->suspended_until_round)) {
                    $user->update(['suspended_until_round' => $targetRound]);
                }
            }

            $affected++;
        }

        return $affected;
    }

    /**
     * Retorna o pagamento do jogador logado para o jogo atual, se existir.
     */
    public function getPlayerPayment(int $userId, int $gameId): ?Payment
    {
        return Payment::where('user_id', $userId)
            ->where('game_id', $gameId)
            ->first();
    }

    /**
     * Retorna todos os pagamentos de um jogo para a view do admin.
     */
    public function getGamePayments(int $gameId): array
    {
        return Payment::where('game_id', $gameId)
            ->with('user:id,name,position')
            ->orderBy('paid_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'user_name' => $p->user->name,
                'amount' => $p->amount,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'penalty_rounds' => $p->penalty_rounds,
            ])
            ->all();
    }
}
