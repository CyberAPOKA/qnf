<?php

namespace App\Console\Commands;

use App\Jobs\ExpireRecSegments;
use Illuminate\Console\Command;

class RecCleanupCommand extends Command
{
    protected $signature = 'rec:cleanup {--sync : Run inline instead of queue}';

    protected $description = 'Expire unpinned REC segments and temporary files';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $count = app(\App\Services\Rec\RecSegmentService::class)->expireUnpinned();
            $this->info("Expired segments: {$count}");

            return self::SUCCESS;
        }

        ExpireRecSegments::dispatch()->onQueue(config('rec.processing_queue'));
        $this->info('ExpireRecSegments dispatched.');

        return self::SUCCESS;
    }
}
