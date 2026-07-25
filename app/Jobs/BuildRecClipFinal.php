<?php

namespace App\Jobs;

use App\Enums\RecClipStatus;
use App\Enums\RecSaveRequestStatus;
use App\Enums\RecSaveTargetStatus;
use App\Events\ClipReady;
use App\Models\RecSaveTarget;
use App\Services\Rec\RecStorageService;
use App\Services\RecClipNormalizeService;
use App\Services\RecSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class BuildRecClipFinal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 300;

    public function __construct(public int $saveTargetId) {}

    public function handle(
        RecClipNormalizeService $normalize,
        RecStorageService $storage,
        RecSessionService $recSession,
    ): void {
        $target = RecSaveTarget::query()
            ->with(['segments', 'saveRequest', 'clip.user'])
            ->find($this->saveTargetId);

        if (! $target || ! $target->clip) {
            return;
        }

        $clip = $target->clip;

        $segmentPaths = $target->segments
            ->filter(fn ($segment) => $segment->file_path && $segment->storage_disk)
            ->sortBy('pivot.order')
            ->map(fn ($segment) => Storage::disk($segment->storage_disk)->path($segment->file_path))
            ->values()
            ->all();

        if ($segmentPaths === []) {
            return;
        }

        $finalRelative = $storage->finalPath(
            $target->saveRequest->game_id,
            $target->saveRequest->uuid,
            $target->camera_tag,
            'final.webm',
        );

        $disk = $storage->disk();
        $absolute = Storage::disk($disk)->path($finalRelative);
        @mkdir(dirname($absolute), 0775, true);

        $result = $normalize->buildFinal($segmentPaths, $absolute);

        if (! $result) {
            $clip->update([
                'status' => RecClipStatus::Failed,
                'failure_code' => 'final_failed',
                'failure_message' => 'Failed to build final clip.',
                'processing_attempts' => $clip->processing_attempts + 1,
            ]);

            return;
        }

        $clip->update([
            'final_file_path' => $finalRelative,
            'file_path' => $finalRelative,
            'storage_disk' => $disk,
            'status' => RecClipStatus::Ready,
            'duration_seconds' => (int) max(1, round($result['duration_seconds'] ?? ($clip->duration_seconds ?? 1))),
            'duration_ms' => ($result['duration_seconds'] ?? null) !== null
                ? (int) round($result['duration_seconds'] * 1000)
                : $clip->duration_ms,
            'bytes' => $result['bytes'] ?? $clip->bytes,
            'processing_finished_at' => now(),
        ]);

        $target->update([
            'status' => RecSaveTargetStatus::Ready,
            'final_ready_at' => now(),
        ]);

        $saveRequest = $target->saveRequest;
        $readyCount = $saveRequest->targets()
            ->where('status', RecSaveTargetStatus::Ready->value)
            ->count();

        $saveRequest->update([
            'ready_count' => $readyCount,
            'status' => $readyCount >= $saveRequest->expected_count
                ? RecSaveRequestStatus::Ready
                : RecSaveRequestStatus::Partial,
            'completed_at' => $readyCount >= $saveRequest->expected_count ? now() : null,
        ]);

        $clip->refresh()->load('user');

        rescue(
            fn () => broadcast(new ClipReady(
                $saveRequest->game_id,
                $saveRequest->uuid,
                $recSession->serializeClip($clip),
            )),
            report: false,
        );
    }
}
