<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\WeekTeamImageService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateWeekTeamImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $gameId
    ) {}

    public function handle(WeekTeamImageService $imageService, WhatsAppService $whatsAppService): void
    {
        $game = Game::with([
            'teams.captain',
            'draftPicks.pickedUser',
        ])->findOrFail($this->gameId);

        $paths = $imageService->generate($game);

        if (empty($paths)) {
            Log::info('Week team image: no winners (all tied)', ['game_id' => $this->gameId]);
            return;
        }

        Log::info('Week team images generated', ['game_id' => $this->gameId, 'paths' => $paths]);

        $round = $game->round ?? $this->gameId;

        foreach ($paths as $path) {
            $fullPath = storage_path('app/public/'.$path);
            $caption = "Time da Semana - Rodada {$round}";

            rescue(
                fn () => $whatsAppService->sendImageToGroup($fullPath, $caption),
                report: false
            );
        }
    }
}
