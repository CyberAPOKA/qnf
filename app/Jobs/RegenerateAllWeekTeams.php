<?php

namespace App\Jobs;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\WeekTeamImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RegenerateAllWeekTeams implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function handle(WeekTeamImageService $imageService): void
    {
        $games = Game::where('status', GameStatus::DONE)->orderBy('round')->get();

        $generated = 0;
        $skipped = 0;
        $failed = [];

        foreach ($games as $game) {
            try {
                if (! empty($game->week_team_images)) {
                    $skipped++;
                    continue;
                }

                $paths = $imageService->generate($game);

                if (empty($paths)) {
                    $failed[] = $game->round;
                    continue;
                }

                $game->update(['week_team_images' => $paths]);
                $generated++;
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $game->round;
            }
        }

        Log::info('RegenerateAllWeekTeams done', [
            'generated' => $generated,
            'skipped' => $skipped,
            'total' => $games->count(),
            'failed_rounds' => $failed,
        ]);
    }
}
