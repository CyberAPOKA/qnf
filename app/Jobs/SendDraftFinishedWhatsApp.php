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
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendDraftFinishedWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 15;
    public int $timeout = 180;

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

        $message = $draftService->buildWhatsAppMessage($game, includeInitialMatchup: true);

        $lineupsPath = rescue(fn () => $lineupsImageService->generate(
            $game,
            $draftService->buildTeamPlayerIdsForLineups($game)
        ));

        if ($lineupsPath) {
            $fullImagePath = storage_path('app/public/' . $lineupsPath);

            if ($whatsAppService->sendImageToGroup($fullImagePath, $message)) {
                return;
            }

            // Image path existed but WhatsApp/Puppeteer failed — retry the job unless
            // this was the last attempt, then fall back to text so the group still gets teams.
            if ($this->attempts() < $this->tries) {
                throw new RuntimeException('WhatsApp draft lineups image send failed; will retry.');
            }

            Log::warning('WhatsApp draft lineups image send failed after retries; falling back to text', [
                'game_id' => $this->gameId,
                'imagePath' => $fullImagePath,
            ]);
        }

        if (! $whatsAppService->sendToGroup($message)) {
            throw new RuntimeException('WhatsApp draft finished text send failed.');
        }
    }
}
