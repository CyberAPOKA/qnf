<?php

namespace App\Console\Commands;

use App\Jobs\ExpireRecRecorderSessions;
use App\Services\Rec\RecRecorderSessionService;
use Illuminate\Console\Command;

class RecExpireSessionsCommand extends Command
{
    protected $signature = 'rec:expire-sessions {--sync : Run inline instead of queue}';

    protected $description = 'Expire REC recorder sessions with elapsed leases';

    public function handle(RecRecorderSessionService $sessions): int
    {
        if ($this->option('sync')) {
            $count = $sessions->expireStaleSessions();
            $this->info("Expired sessions: {$count}");

            return self::SUCCESS;
        }

        ExpireRecRecorderSessions::dispatch()->onQueue(config('rec.processing_queue'));
        $this->info('ExpireRecRecorderSessions dispatched.');

        return self::SUCCESS;
    }
}
