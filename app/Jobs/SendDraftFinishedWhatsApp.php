<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\DraftService;
use App\Services\LineupsImageService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDraftFinishedWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    public int $timeout = 120;

    public function __construct(public int $gameId) {}

    public function handle(
        DraftService $draftService,
        LineupsImageService $lineupsImageService,
        WhatsAppService $whatsAppService,
    ): void {
        $game = Game::with(['teams.captain', 'draftPicks.pickedUser', 'players'])->find($this->gameId);

        if (! $game) {
            return;
        }

        $message = $draftService->buildWhatsAppMessage($game);

        $lineupsPath = rescue(fn () => $lineupsImageService->generate(
            $game,
            $draftService->buildTeamPlayerIdsForLineups($game)
        ));

        if ($lineupsPath) {
            $fullImagePath = storage_path('app/public/' . $lineupsPath);
            $whatsAppService->sendImageToGroup($fullImagePath, $message);
            return;
        }

        $whatsAppService->sendToGroup($message);
    }
}
