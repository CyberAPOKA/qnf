<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\DraftNarration\DraftNarrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDraftNarrationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    public int $timeout = 540;

    public int $uniqueFor = 600;

    public function __construct(public int $gameId)
    {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return 'draft-narrations-'.$this->gameId;
    }

    public function handle(DraftNarrationService $service): void
    {
        if (! config('fish-audio.enabled')) {
            return;
        }

        $game = Game::with(['teams.captain', 'draftPicks.pickedUser'])->find($this->gameId);

        if (! $game) {
            return;
        }

        $service->processGame($game);
    }
}
