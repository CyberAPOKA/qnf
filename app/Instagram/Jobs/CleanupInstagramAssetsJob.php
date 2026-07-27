<?php

namespace App\Instagram\Jobs;

use App\Instagram\Enums\InstagramPublicationStatus;
use App\Instagram\Services\InstagramAssetService;
use App\Models\InstagramPublication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupInstagramAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $afterCommit = true;

    public function __construct()
    {
        $this->onQueue((string) config('instagram.queue', 'default'));
    }

    public function handle(InstagramAssetService $assetService): void
    {
        $publishedRetentionHours = max(1, (int) config('instagram.asset_retention_hours', 48));
        $failedRetentionDays = max(1, (int) config('instagram.failed_asset_retention_days', 14));

        $publishedCutoff = now()->subHours($publishedRetentionHours);
        $failedCutoff = now()->subDays($failedRetentionDays);

        $deleted = 0;

        InstagramPublication::query()
            ->whereIn('status', [
                InstagramPublicationStatus::Published->value,
                InstagramPublicationStatus::DryRunCompleted->value,
                InstagramPublicationStatus::Cancelled->value,
            ])
            ->where(function ($query) use ($publishedCutoff): void {
                $query->where('published_at', '<=', $publishedCutoff)
                    ->orWhere(function ($inner) use ($publishedCutoff): void {
                        $inner->whereNull('published_at')
                            ->where('updated_at', '<=', $publishedCutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(50, function ($publications) use ($assetService, &$deleted): void {
                foreach ($publications as $publication) {
                    $assetService->cleanupPublication($publication->uuid);
                    $publication->items()->update([
                        'local_path' => null,
                        'public_url' => null,
                    ]);
                    $deleted++;
                }
            });

        InstagramPublication::query()
            ->where('status', InstagramPublicationStatus::Failed->value)
            ->where(function ($query) use ($failedCutoff): void {
                $query->where('failed_at', '<=', $failedCutoff)
                    ->orWhere(function ($inner) use ($failedCutoff): void {
                        $inner->whereNull('failed_at')
                            ->where('updated_at', '<=', $failedCutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(50, function ($publications) use ($assetService, &$deleted): void {
                foreach ($publications as $publication) {
                    $assetService->cleanupPublication($publication->uuid);
                    $publication->items()->update([
                        'local_path' => null,
                        'public_url' => null,
                    ]);
                    $deleted++;
                }
            });

        $this->cleanupOrphanDirectories($assetService);

        Log::info('Instagram asset cleanup completed', [
            'publications_cleaned' => $deleted,
        ]);
    }

    private function cleanupOrphanDirectories(InstagramAssetService $assetService): void
    {
        $disk = Storage::disk('public');
        $root = 'instagram/publications';

        if (! $disk->exists($root)) {
            return;
        }

        foreach ($disk->directories($root) as $directory) {
            $uuid = basename($directory);
            $exists = InstagramPublication::query()->where('uuid', $uuid)->exists();

            if ($exists) {
                continue;
            }

            $assetService->cleanupPublication($uuid);
        }
    }
}
