<?php

namespace App\Jobs;

use App\Enums\RecClipStatus;
use App\Enums\RecSaveTargetStatus;
use App\Events\ClipPreviewReady;
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

class BuildRecClipPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 180;

    public function __construct(public int $saveTargetId) {}

    public function handle(
        RecClipNormalizeService $normalize,
        RecStorageService $storage,
        RecSessionService $recSession,
    ): void {
        $target = RecSaveTarget::query()
            ->with(['segments', 'saveRequest', 'clip.user'])
            ->find($this->saveTargetId);

        if (! $target) {
            return;
        }

        $clip = $target->clip;

        if (! $clip) {
            return;
        }

        $segmentPaths = $target->segments
            ->filter(fn ($segment) => $segment->file_path && $segment->storage_disk)
            ->sortBy('pivot.order')
            ->map(fn ($segment) => Storage::disk($segment->storage_disk)->path($segment->file_path))
            ->values()
            ->all();

        if ($segmentPaths === []) {
            return;
        }

        $previewRelative = $storage->previewPath(
            $target->saveRequest->game_id,
            $target->saveRequest->uuid,
            $target->camera_tag,
            'preview.webm',
        );

        $disk = $storage->disk();
        $absolute = Storage::disk($disk)->path($previewRelative);
        @mkdir(dirname($absolute), 0775, true);

        $result = $normalize->buildPreview($segmentPaths, $absolute);

        if (! $result) {
            $clip->update([
                'status' => RecClipStatus::Failed,
                'failure_code' => 'preview_failed',
                'failure_message' => 'Failed to build preview.',
                'processing_attempts' => $clip->processing_attempts + 1,
            ]);

            return;
        }

        $clip->update([
            'preview_file_path' => $previewRelative,
            'file_path' => $previewRelative,
            'storage_disk' => $disk,
            'status' => RecClipStatus::PreviewReady,
            'duration_seconds' => (int) max(1, round($result['duration_seconds'] ?? ($clip->duration_seconds ?? 1))),
            'duration_ms' => ($result['duration_seconds'] ?? null) !== null
                ? (int) round($result['duration_seconds'] * 1000)
                : $clip->duration_ms,
            'bytes' => $result['bytes'] ?? $clip->bytes,
            'processing_started_at' => $clip->processing_started_at ?? now(),
            'processing_finished_at' => now(),
        ]);

        $target->update([
            'status' => RecSaveTargetStatus::PreviewReady,
            'preview_ready_at' => now(),
        ]);

        $clip->refresh()->load('user');

        rescue(
            fn () => broadcast(new ClipPreviewReady(
                $target->saveRequest->game_id,
                $target->saveRequest->uuid,
                $recSession->serializeClip($clip),
                [
                    'id' => $target->id,
                    'camera_tag' => $target->camera_tag,
                    'status' => $target->status?->value ?? $target->status,
                ],
            )),
            report: false,
        );

        BuildRecClipFinal::dispatch($target->id)
            ->onQueue(config('rec.processing_queue'));
    }
}
