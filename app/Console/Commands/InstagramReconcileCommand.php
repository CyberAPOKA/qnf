<?php

namespace App\Console\Commands;

use App\Instagram\Jobs\ReconcileInstagramPublicationsJob;
use Illuminate\Console\Command;
use Throwable;

class InstagramReconcileCommand extends Command
{
    protected $signature = 'instagram:reconcile';

    protected $description = 'Reconcilia publicações Instagram travadas e reenfileira o processamento';

    public function handle(): int
    {
        try {
            ReconcileInstagramPublicationsJob::dispatchSync();
        } catch (Throwable $e) {
            $this->error('Falha na reconciliação: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Reconciliação Instagram concluída.');

        return self::SUCCESS;
    }
}
