<?php

namespace App\Instagram\Jobs;

use App\Instagram\Enums\InstagramPublicationStatus;
use App\Models\InstagramPublication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileInstagramPublicationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->afterCommit = true;
        $this->onQueue((string) config('instagram.queue', 'default'));
    }

    public function handle(): void
    {
        $stuckBefore = now()->subMinutes(10);
        $redispatched = 0;

        $statuses = [
            InstagramPublicationStatus::Pending->value,
            InstagramPublicationStatus::Preparing->value,
            InstagramPublicationStatus::Rendering->value,
            InstagramPublicationStatus::Validating->value,
            InstagramPublicationStatus::CreatingContainers->value,
            InstagramPublicationStatus::WaitingContainers->value,
            InstagramPublicationStatus::Publishing->value,
            InstagramPublicationStatus::Deferred->value,
        ];

        InstagramPublication::query()
            ->whereIn('status', $statuses)
            ->where(function ($query) use ($stuckBefore): void {
                $query->where('updated_at', '<=', $stuckBefore)
                    ->orWhere(function ($q): void {
                        $q->where('status', InstagramPublicationStatus::Pending->value)
                            ->where('attempts', 0);
                    });
            })
            ->orderBy('id')
            ->chunkById(50, function ($publications) use (&$redispatched): void {
                foreach ($publications as $publication) {
                    ProcessInstagramPublicationJob::dispatch($publication->id)
                        ->onQueue((string) config('instagram.queue', 'default'));

                    $redispatched++;

                    Log::info('Redispatched stuck Instagram publication', [
                        'publication_uuid' => $publication->uuid,
                        'publication_id' => $publication->id,
                        'status' => $publication->status?->value,
                    ]);
                }
            });

        Log::info('Instagram reconcile job completed', [
            'redispatched' => $redispatched,
        ]);
    }
}
