<?php

namespace App\Jobs;

use App\Events\ClipPreviewReady;
use App\Events\ClipReady;
use App\Events\SaveClipRequested;
use App\Models\RecOutboxEvent;
use App\Services\RecSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PublishRecOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 5;

    public function __construct(public int $outboxEventId) {}

    public function handle(RecSessionService $recSession): void
    {
        $event = RecOutboxEvent::query()->find($this->outboxEventId);

        if (! $event || $event->status === 'published') {
            return;
        }

        try {
            $this->publish($event, $recSession);

            $event->update([
                'status' => 'published',
                'published_at' => now(),
                'attempts' => $event->attempts + 1,
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $event->update([
                'attempts' => $event->attempts + 1,
                'last_error' => Str::limit($e->getMessage(), 1000),
                'available_at' => now()->addSeconds(5 * max(1, $event->attempts + 1)),
            ]);

            throw $e;
        }
    }

    private function publish(RecOutboxEvent $event, RecSessionService $recSession): void
    {
        $payload = $event->payload ?? [];
        $gameId = (int) ($event->game_id ?? $payload['game_id'] ?? 0);

        match ($event->event_type) {
            'save_created', 'SaveClipRequested', 'save_requested' => broadcast(new SaveClipRequested(
                $gameId,
                (string) ($payload['save_request_uuid'] ?? $payload['uuid'] ?? ''),
                (int) ($payload['save_request_id'] ?? 0),
                (string) ($payload['triggered_by_name'] ?? ''),
                (int) ($payload['expected_recorders'] ?? $payload['expected_count'] ?? 0),
                (string) ($payload['capture_scope'] ?? 'all'),
                $payload['camera_tags'] ?? $recSession->cameraTagsForScope($payload['capture_scope'] ?? 'all'),
                (int) ($payload['cooldown_seconds'] ?? $recSession->scopeCooldownSeconds()),
                $payload['locked_scopes'] ?? $recSession->scopesLockedBy($payload['capture_scope'] ?? 'all'),
            )),
            'clip_preview_ready', 'ClipPreviewReady' => broadcast(new ClipPreviewReady(
                $gameId,
                (string) ($payload['save_request_uuid'] ?? ''),
                $payload['clip'] ?? [],
                $payload['target'] ?? [],
            )),
            'clip_ready', 'ClipReady' => broadcast(new ClipReady(
                $gameId,
                (string) ($payload['save_request_uuid'] ?? ''),
                $payload['clip'] ?? [],
            )),
            default => Log::info('REC outbox unknown event type', [
                'uuid' => $event->uuid,
                'event_type' => $event->event_type,
            ]),
        };
    }
}
