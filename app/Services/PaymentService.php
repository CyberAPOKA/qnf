<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Payment;
use App\Models\User;
use App\Support\PersonName;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public const TZ = 'America/Sao_Paulo';

    public function __construct(
        private readonly MercadoPagoService $mercadoPagoService,
    ) {}

    /**
     * Garante a cobrança Pix do jogador, criando-a se ainda não existir.
     */
    public function ensurePaymentForPlayer(Game $game, User $player, ?string $deviceSessionId = null): ?Payment
    {
        if (! $this->playerIsEligible($game, $player)) {
            return null;
        }

        $this->createPaymentForPlayer($game, $player, $deviceSessionId);

        return $this->getPlayerPayment($player->id, $game->id);
    }

    /**
     * Cria cobrança Pix para um jogador específico.
     */
    public function createPaymentForPlayer(Game $game, User $player, ?string $deviceSessionId = null): bool
    {
        if (! config('services.mercadopago.active', true)) {
            return false;
        }

        if (! $this->playerIsEligible($game, $player)) {
            return false;
        }

        $payment = $this->lockOrCreateLocalPayment($game, $player);

        if ($payment->external_id) {
            return false;
        }

        $this->chargeOnMercadoPago($payment, $game, $player, $deviceSessionId);

        return true;
    }

    /**
     * Cria cobranças para todos os jogadores elegíveis de um jogo.
     */
    public function createPaymentsForGame(Game $game): int
    {
        $game->loadMissing('players');

        $count = 0;

        foreach ($game->players as $player) {
            try {
                if ($this->createPaymentForPlayer($game, $player)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error("Falha ao criar pagamento para jogador {$player->id}: {$e->getMessage()}");
            }
        }

        return $count;
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
    public function confirmPayment(Payment $payment, string $method = Payment::METHOD_SYSTEM): void
    {
        if ($payment->isPaid()) {
            return;
        }

        DB::transaction(function () use ($payment, $method): void {
            $payment->update(['paid_at' => now(), 'method' => $method]);

            $user = $payment->user;

            if ($payment->penalty_rounds >= 3) {
                $latestRound = Game::where('status', GameStatus::DONE->value)
                    ->orderByDesc('date')
                    ->value('round') ?? $payment->game->round;

                $user->update(['suspended_until_round' => $latestRound + 3]);
            } else {
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

            $wednesdayDeadline = $gameDate->addDays(2)->setTime(0, 15);
            $thursdayDeadline = $gameDate->addDays(3)->setTime(0, 15);
            $fridayDeadline = $gameDate->addDays(4)->setTime(0, 15);

            $newPenalty = 0;
            if ($now->gte($fridayDeadline)) {
                $newPenalty = 3;
            } elseif ($now->gte($thursdayDeadline)) {
                $newPenalty = 2;
            } elseif ($now->gte($wednesdayDeadline)) {
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
     * @return array<string, mixed>|null
     */
    public function playerPayload(?Payment $payment): ?array
    {
        if (! $payment) {
            return null;
        }

        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'pix_payload' => $payment->pix_payload,
            'qr_code_base64' => $payment->qr_code_base64,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'penalty_rounds' => $payment->penalty_rounds,
        ];
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

    private function playerIsEligible(Game $game, User $player): bool
    {
        if ($player->position === Position::GOALKEEPER || $player->guest) {
            return false;
        }

        return GamePlayer::where('game_id', $game->id)
            ->where('user_id', $player->id)
            ->where('dropped_out', false)
            ->whereNull('waitlist_at')
            ->exists();
    }

    private function lockOrCreateLocalPayment(Game $game, User $player): Payment
    {
        try {
            return DB::transaction(function () use ($game, $player): Payment {
                $payment = Payment::where('game_id', $game->id)
                    ->where('user_id', $player->id)
                    ->lockForUpdate()
                    ->first();

                if ($payment) {
                    if (blank($payment->idempotency_key)) {
                        $payment->update(['idempotency_key' => (string) Str::uuid()]);
                    }

                    return $payment->fresh();
                }

                return Payment::create([
                    'game_id' => $game->id,
                    'user_id' => $player->id,
                    'amount' => (int) config('services.pix.amount', 800),
                    'pix_payload' => '',
                    'idempotency_key' => (string) Str::uuid(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return Payment::where('game_id', $game->id)
                ->where('user_id', $player->id)
                ->firstOrFail();
        }
    }

    private function chargeOnMercadoPago(Payment $payment, Game $game, User $player, ?string $deviceSessionId): void
    {
        $amount = (int) ($payment->amount ?: config('services.pix.amount', 800));
        $externalRef = "QNF-G{$game->id}-U{$player->id}";

        $mpData = $this->mercadoPagoService->createPixPayment(
            $amount,
            "QNF Futsal - Rodada {$game->round}",
            $externalRef,
            $this->payerFromPlayer($player),
            $payment->idempotency_key,
            $deviceSessionId,
        );

        $payment->update([
            'amount' => $amount,
            'pix_payload' => $mpData['qr_code'],
            'external_id' => (string) $mpData['id'],
            'qr_code_base64' => $mpData['qr_code_base64'],
        ]);

        if (($mpData['status'] ?? '') === 'approved') {
            $this->confirmPayment($payment->fresh());
        }
    }

    /**
     * @return array{email?: string, first_name?: string, last_name?: string}
     */
    private function payerFromPlayer(User $player): array
    {
        $names = PersonName::split($player->name);
        $payer = [];

        if (filled($player->email)) {
            $payer['email'] = $player->email;
        }

        if (filled($names['first_name'])) {
            $payer['first_name'] = $names['first_name'];
        }

        if (filled($names['last_name'])) {
            $payer['last_name'] = $names['last_name'];
        }

        return $payer;
    }
}
