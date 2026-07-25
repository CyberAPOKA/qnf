<?php

namespace App\Jobs;

use App\Services\Rec\RecRecorderSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpireRecRecorderSessions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(RecRecorderSessionService $sessions): void
    {
        $sessions->expireStaleSessions();
    }
}
