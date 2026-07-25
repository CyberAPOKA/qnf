<?php

namespace App\Jobs;

use App\Enums\RecClipStatus;
use App\Enums\RecSaveTargetStatus;
use App\Models\RecClip;
use App\Models\RecSaveTarget;
use App\Services\Rec\RecStorageService;
use App\Services\RecClipNormalizeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FinalizeRecSaveTarget implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 180;

    public function __construct(public int $saveTargetId) {}

    public function handle(RecStorageService $storage, RecClipNormalizeService $normalize): void
    {
        $target = RecSaveTarget::query()
            ->with(['segments', 'saveRequest', 'recorderSession', 'clip'])
            ->find($this->saveTargetId);

        if (! $target) {
            return;
        }

        $segments = $target->segments
            ->filter(fn ($segment) => filled($segment->file_path) && filled($segment->storage_disk))
            ->sortBy('pivot.order')
            ->values();

        if ($segments->isEmpty()) {
            return;
        }

        if ($target->expected_until && $target->expected_until->isFuture()) {
            self::dispatch($target->id)
                ->delay($target->expected_until)
                ->onQueue(config('rec.processing_queue'));

            return;
        }

        $disk = $storage->disk();
        $rawRelative = $storage->rawPath(
            $target->saveRequest->game_id,
            $target->saveRequest->uuid,
            $target->camera_tag,
            'raw-'.Str::uuid().'.webm',
        );

        $absolute = Storage::disk($disk)->path($rawRelative);
        @mkdir(dirname($absolute), 0775, true);

        $segmentPaths = $segments
            ->map(fn ($segment) => Storage::disk($segment->storage_disk)->path($segment->file_path))
            ->all();

        $built = $normalize->buildFinal($segmentPaths, $absolute);

        if (! $built) {
            // Fallback: copy first segment as raw so preview can still attempt recovery.
            $first = $segments->first();
            Storage::disk($disk)->put(
                $rawRelative,
                Storage::disk($first->storage_disk)->get($first->file_path),
            );
        }

        $clip = $target->clip ?? RecClip::create([
            'rec_save_request_id' => $target->rec_save_request_id,
            'rec_save_target_id' => $target->id,
            'game_id' => $target->saveRequest->game_id,
            'user_id' => $target->recorderSession?->user_id ?? $target->saveRequest->triggered_by,
            'recorder_id' => $target->recorderSession?->uuid ?? $target->camera_tag,
            'camera_tag' => $target->camera_tag,
            'file_path' => $rawRelative,
            'raw_file_path' => $rawRelative,
            'storage_disk' => $disk,
            'status' => RecClipStatus::RawReady,
            'duration_seconds' => (int) ($built['duration_seconds'] ?? config('rec.buffer_seconds')),
            'duration_ms' => isset($built['duration_seconds'])
                ? (int) round($built['duration_seconds'] * 1000)
                : null,
            'bytes' => $built['bytes'] ?? null,
            'processing_started_at' => now(),
        ]);

        if ($target->clip) {
            $clip->update([
                'raw_file_path' => $rawRelative,
                'file_path' => $rawRelative,
                'storage_disk' => $disk,
                'status' => RecClipStatus::RawReady,
                'duration_seconds' => (int) ($built['duration_seconds'] ?? $clip->duration_seconds),
                'bytes' => $built['bytes'] ?? $clip->bytes,
                'processing_started_at' => $clip->processing_started_at ?? now(),
            ]);
        }

        $target->update([
            'status' => RecSaveTargetStatus::RawReady,
            'raw_ready_at' => $target->raw_ready_at ?? now(),
            'segments_received' => $segments->count(),
        ]);

        BuildRecClipPreview::dispatch($target->id)
            ->onQueue(config('rec.processing_queue'));
    }
}
