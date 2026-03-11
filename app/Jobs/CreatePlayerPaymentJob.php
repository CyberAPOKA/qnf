<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreatePlayerPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public int $gameId, public int $userId) {}

    public function handle(PaymentService $paymentService): void
    {
        $game = Game::findOrFail($this->gameId);
        $player = User::findOrFail($this->userId);
        $paymentService->createPaymentForPlayer($game, $player);
    }
}
