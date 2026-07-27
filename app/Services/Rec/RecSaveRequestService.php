<?php

namespace App\Services\Rec;

use App\Enums\RecRecorderSessionStatus;
use App\Enums\RecSaveRequestStatus;
use App\Enums\RecSaveTargetStatus;
use App\Enums\RecSegmentStatus;
use App\Jobs\FinalizeRecSaveTarget;
use App\Jobs\PublishRecOutboxEvent;
use App\Models\Game;
use App\Models\RecOutboxEvent;
use App\Models\RecRecorderSession;
use App\Models\RecSaveRequest;
use App\Models\RecSaveTarget;
use App\Models\RecSaveTargetSegment;
use App\Models\RecSegment;
use App\Models\User;
use App\Services\RecSessionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RecSaveRequestService
{
    public function __construct(
        private readonly RecSegmentService $segments,
        private readonly RecSessionService $recSession,
    ) {}

    /**
     * @return array{save_request: RecSaveRequest, targets: \Illuminate\Support\Collection<int, RecSaveTarget>}
     */
    public function create(Game $game, User $user, string $captureScope = 'all', ?string $idempotencyKey = null): array
    {
        $debounceMs = (int) config('rec.save_debounce_milliseconds', 800);

        if ($idempotencyKey) {
            $debounceKey = "rec:game:{$game->id}:save-debounce:{$idempotencyKey}";
            $ttlSeconds = max(1, (int) ceil($debounceMs / 1000));

            if (! Cache::add($debounceKey, true, now()->addSeconds($ttlSeconds))) {
                $existing = RecSaveRequest::query()
                    ->where('game_id', $game->id)
                    ->where('triggered_by', $user->id)
                    ->latest('id')
                    ->with('targets')
                    ->first();

                if ($existing) {
                    return [
                        'save_request' => $existing,
                        'targets' => $existing->targets,
                    ];
                }
            }
        } elseif ($debounceMs > 0) {
            $debounceKey = "rec:game:{$game->id}:user:{$user->id}:save-debounce";
            Cache::add($debounceKey, true, now()->addMilliseconds($debounceMs));
        }

        $scopeLock = $this->recSession->acquireScopeCooldown($game->id, $captureScope);

        if (! $scopeLock['ok']) {
            throw new HttpException(
                429,
                "Aguarde {$scopeLock['retry_after']}s para salvar este lado novamente.",
                null,
                [
                    'Retry-After' => (string) $scopeLock['retry_after'],
                ],
            );
        }

        $cameraTags = $this->cameraTagsForScope($captureScope);
        $triggeredAt = now();
        $bufferSeconds = (int) config('rec.buffer_seconds');
        $postRollSeconds = (int) config('rec.post_roll_seconds');
        $captureFrom = $triggeredAt->copy()->subSeconds($bufferSeconds);
        $captureUntil = $triggeredAt->copy()->addSeconds($postRollSeconds);
        $deadlineAt = $captureUntil->copy()->addSeconds((int) config('rec.server_retention_seconds'));

        $activeSessions = RecRecorderSession::query()
            ->where('game_id', $game->id)
            ->whereIn('camera_tag', $cameraTags)
            ->whereIn('status', [
                RecRecorderSessionStatus::Starting->value,
                RecRecorderSessionStatus::Recording->value,
                RecRecorderSessionStatus::Degraded->value,
                RecRecorderSessionStatus::Reconnecting->value,
            ])
            ->where(function ($query) {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '>=', now());
            })
            ->get()
            ->keyBy('camera_tag');

        if ($activeSessions->isEmpty()) {
            throw new HttpException(
                422,
                $captureScope === 'all'
                    ? 'Nenhuma câmera gravando no momento.'
                    : 'Nenhuma câmera desse lado está gravando no momento.',
            );
        }

        $readyTargetIds = [];

        $result = DB::transaction(function () use (
            $game,
            $user,
            $captureScope,
            $cameraTags,
            $triggeredAt,
            $captureFrom,
            $captureUntil,
            $deadlineAt,
            $activeSessions,
            $scopeLock,
            &$readyTargetIds,
        ) {
            $saveRequest = RecSaveRequest::create([
                'game_id' => $game->id,
                'triggered_by' => $user->id,
                'uuid' => (string) Str::uuid(),
                'capture_scope' => $captureScope,
                'status' => RecSaveRequestStatus::Collecting,
                'triggered_at' => $triggeredAt,
                'capture_from' => $captureFrom,
                'capture_until' => $captureUntil,
                'expected_count' => $activeSessions->count(),
                'acknowledged_count' => 0,
                'received_count' => 0,
                'ready_count' => 0,
                'failed_count' => 0,
                'deadline_at' => $deadlineAt,
            ]);

            $targets = collect();

            foreach ($cameraTags as $cameraTag) {
                /** @var RecRecorderSession|null $session */
                $session = $activeSessions->get($cameraTag);

                if (! $session) {
                    continue;
                }

                $target = RecSaveTarget::create([
                    'rec_save_request_id' => $saveRequest->id,
                    'recorder_session_id' => $session->id,
                    'camera_tag' => $cameraTag,
                    'status' => RecSaveTargetStatus::Collecting,
                    'expected_from' => $captureFrom,
                    'expected_until' => $captureUntil,
                    'segments_expected' => 0,
                    'segments_received' => 0,
                    'segments_missing' => 0,
                ]);

                $pinned = $this->pinSegmentsForTarget($target, $session, $captureFrom, $captureUntil);
                $target->update([
                    'segments_expected' => max($pinned, 1),
                    'segments_received' => $pinned,
                    'segments_missing' => max(0, max($pinned, 1) - $pinned),
                    'status' => $pinned > 0 && $captureUntil->lessThanOrEqualTo(now())
                        ? RecSaveTargetStatus::RawReady
                        : RecSaveTargetStatus::Collecting,
                    'raw_ready_at' => $pinned > 0 && $captureUntil->lessThanOrEqualTo(now())
                        ? now()
                        : null,
                ]);

                if ($target->status === RecSaveTargetStatus::RawReady) {
                    $readyTargetIds[] = $target->id;
                }

                $targets->push($target->fresh());
            }

            $outbox = RecOutboxEvent::create([
                'uuid' => (string) Str::uuid(),
                'game_id' => $game->id,
                'event_type' => 'save_requested',
                'payload' => [
                    'save_request_uuid' => $saveRequest->uuid,
                    'save_request_id' => $saveRequest->id,
                    'capture_scope' => $captureScope,
                    'camera_tags' => $targets->pluck('camera_tag')->values()->all(),
                    'triggered_by' => $user->id,
                    'triggered_by_name' => $user->name,
                    'expected_count' => $targets->count(),
                    'cooldown_seconds' => $scopeLock['cooldown_seconds'],
                    'locked_scopes' => $scopeLock['locked_scopes'],
                ],
                'status' => 'pending',
                'available_at' => now(),
            ]);

            return [
                'save_request' => $saveRequest->fresh(['targets', 'triggeredBy']),
                'targets' => $targets,
                'outbox' => $outbox,
                'scope_lock' => $scopeLock,
            ];
        });

        PublishRecOutboxEvent::dispatch($result['outbox']->id)
            ->onQueue(config('rec.processing_queue'));

        foreach ($readyTargetIds as $targetId) {
            FinalizeRecSaveTarget::dispatch($targetId)
                ->onQueue(config('rec.processing_queue'));
        }

        return [
            'save_request' => $result['save_request'],
            'targets' => $result['targets'],
            'scope_lock' => $result['scope_lock'],
        ];
    }

    public function acknowledge(
        RecSaveRequest $saveRequest,
        RecRecorderSession $session,
        array $payload = [],
    ): RecSaveTarget {
        $target = RecSaveTarget::query()
            ->where('rec_save_request_id', $saveRequest->id)
            ->where('recorder_session_id', $session->id)
            ->firstOrFail();

        if ($target->acknowledged_at === null) {
            $target->update([
                'acknowledged_at' => now(),
                'status' => $target->status === RecSaveTargetStatus::WaitingAck
                    ? RecSaveTargetStatus::Collecting
                    : $target->status,
            ]);

            $saveRequest->increment('acknowledged_count');
        }

        return $target->fresh();
    }

    public function pendingForSession(RecRecorderSession $session, ?int $afterId = null): array
    {
        $query = RecSaveTarget::query()
            ->with('saveRequest')
            ->where('recorder_session_id', $session->id)
            ->whereIn('status', [
                RecSaveTargetStatus::WaitingAck->value,
                RecSaveTargetStatus::Collecting->value,
                RecSaveTargetStatus::RawReady->value,
            ])
            ->orderBy('id');

        if ($afterId) {
            $query->where('id', '>', $afterId);
        }

        return $query->limit(50)->get()->all();
    }

    public function cameraTagsForScope(string $captureScope): array
    {
        $map = config('rec.scope_camera_tags', []);

        return $map[$captureScope] ?? ($map['all'] ?? ['A1', 'A2', 'B1', 'B2']);
    }

    private function pinSegmentsForTarget(
        RecSaveTarget $target,
        RecRecorderSession $session,
        $captureFrom,
        $captureUntil,
    ): int {
        $segments = RecSegment::query()
            ->where('recorder_session_id', $session->id)
            ->whereIn('status', [
                RecSegmentStatus::Received->value,
                RecSegmentStatus::Verified->value,
                RecSegmentStatus::Pinned->value,
            ])
            ->where(function ($query) use ($captureFrom, $captureUntil) {
                $query->where(function ($inner) use ($captureFrom, $captureUntil) {
                    $inner->where('estimated_server_started_at', '<=', $captureUntil)
                        ->where('estimated_server_ended_at', '>=', $captureFrom);
                })->orWhere(function ($inner) use ($captureFrom, $captureUntil) {
                    $inner->whereNull('estimated_server_started_at')
                        ->where('client_started_at', '<=', $captureUntil)
                        ->where('client_ended_at', '>=', $captureFrom);
                });
            })
            ->orderBy('sequence')
            ->get();

        $order = 0;
        $pinnedUntil = $captureUntil->copy()->addDays((int) config('rec.raw_retention_days', 7));

        foreach ($segments as $segment) {
            $this->segments->pin($segment, $pinnedUntil);

            RecSaveTargetSegment::query()->firstOrCreate(
                [
                    'rec_save_target_id' => $target->id,
                    'rec_segment_id' => $segment->id,
                ],
                [
                    'order' => $order++,
                    'created_at' => now(),
                ],
            );
        }

        return $segments->count();
    }
}
