<?php

namespace App\Console\Commands;

use App\Instagram\Jobs\CleanupInstagramAssetsJob;
use Illuminate\Console\Command;
use Throwable;

class InstagramCleanupAssetsCommand extends Command
{
    protected $signature = 'instagram:cleanup-assets';

    protected $description = 'Remove assets locais antigos de publicações Instagram';

    public function handle(): int
    {
        try {
            CleanupInstagramAssetsJob::dispatch()
                ->onQueue((string) config('instagram.queue', 'default'));
        } catch (Throwable $e) {
            $this->error('Falha ao enfileirar limpeza de assets: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Job de limpeza de assets Instagram enfileirado.');

        return self::SUCCESS;
    }
}
