<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileRecSaveRequest;
use App\Models\RecSaveRequest;
use Illuminate\Console\Command;

class RecReconcileCommand extends Command
{
    protected $signature = 'rec:reconcile
        {--dry-run : Report without dispatching jobs}
        {--fix : Attempt repairs}
        {--game= : Limit to a game id}
        {--save= : Limit to a save request uuid}';

    protected $description = 'Reconcile stuck REC save requests and targets';

    public function handle(): int
    {
        $query = RecSaveRequest::query()->with('targets');

        if ($gameId = $this->option('game')) {
            $query->where('game_id', $gameId);
        }

        if ($saveUuid = $this->option('save')) {
            $query->where('uuid', $saveUuid);
        }

        $saves = $query->limit(200)->get();
        $this->info('Save requests: '.$saves->count());

        foreach ($saves as $save) {
            $this->line(sprintf(
                '- %s status=%s expected=%d ready=%d targets=%d',
                $save->uuid,
                $save->status?->value ?? 'n/a',
                $save->expected_count,
                $save->ready_count,
                $save->targets->count(),
            ));
        }

        if ($this->option('dry-run') || ! $this->option('fix')) {
            return self::SUCCESS;
        }

        foreach ($saves as $save) {
            ReconcileRecSaveRequest::dispatch($save->id)
                ->onQueue(config('rec.processing_queue'));
        }

        $this->info('Dispatched reconcile jobs.');

        return self::SUCCESS;
    }
}
