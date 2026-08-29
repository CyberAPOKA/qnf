<?php

namespace App\Listeners;

use App\Events\DraftFinished;
use App\Jobs\GenerateDraftNarrationsJob;

class GenerateDraftNarrationsListener
{
    public function handle(DraftFinished $event): void
    {
        if (! config('fish-audio.enabled')) {
            return;
        }

        $gameId = (int) ($event->game['id'] ?? 0);

        if ($gameId <= 0) {
            return;
        }

        GenerateDraftNarrationsJob::dispatch($gameId);
    }
}
