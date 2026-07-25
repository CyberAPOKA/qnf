<?php

namespace App\Http\Controllers;

use App\Http\Requests\Rec\CreateRecSaveRequest;
use App\Http\Requests\Rec\HeartbeatRecSessionRequest;
use App\Http\Requests\Rec\StartRecSessionRequest;
use App\Http\Requests\Rec\UploadRecSegmentRequest;
use App\Models\Game;
use App\Models\RecRecorderSession;
use App\Models\RecSaveRequest;
use App\Services\Rec\RecRecorderSessionService;
use App\Services\Rec\RecSaveRequestService;
use App\Services\Rec\RecSegmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RecV2Controller extends Controller
{
    public function __construct(
        private readonly RecRecorderSessionService $sessions,
        private readonly RecSegmentService $segments,
        private readonly RecSaveRequestService $saves,
    ) {}

    public function startSession(StartRecSessionRequest $request, Game $game): JsonResponse
    {
        try {
            $result = $this->sessions->start(
                $game,
                $request->user(),
                $request->validated('camera_tag'),
                $request->validated('capabilities') ?? [],
                $request->validated('client') ?? [],
            );
        } catch (ConflictHttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'camera_tag' => $request->validated('camera_tag'),
            ], 409);
        }

        $session = $result['session'];

        return response()->json([
            'session' => [
                'uuid' => $session->uuid,
                'camera_tag' => $session->camera_tag,
                'status' => $session->status->value,
                'lease_expires_at' => $session->lease_expires_at?->toIso8601String(),
                'token' => $result['token'],
            ],
            'config' => $result['config'],
            'server_time_ms' => (int) floor(microtime(true) * 1000),
        ], 201);
    }

    public function heartbeat(
        HeartbeatRecSessionRequest $request,
        Game $game,
        RecRecorderSession $session,
    ): JsonResponse {
        $this->assertSessionGame($game, $session);

        try {
            $result = $this->sessions->heartbeat(
                $session,
                $this->sessionToken($request),
                $request->validated(),
            );
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $fresh = $result['session'];

        return response()->json([
            'session' => [
                'uuid' => $fresh->uuid,
                'camera_tag' => $fresh->camera_tag,
                'status' => $fresh->status->value,
                'lease_expires_at' => $fresh->lease_expires_at?->toIso8601String(),
            ],
            'time_sync' => $result['time_sync'],
            'pending_saves' => $result['pending_saves'],
            'config' => $this->sessions->clientConfig(),
            'server_time_ms' => (int) floor(microtime(true) * 1000),
        ]);
    }

    public function stopSession(Request $request, Game $game, RecRecorderSession $session): JsonResponse
    {
        $this->assertSessionGame($game, $session);

        $fresh = $this->sessions->stop($session, $this->sessionToken($request));

        return response()->json([
            'session' => [
                'uuid' => $fresh->uuid,
                'status' => $fresh->status->value,
                'stopped_at' => $fresh->stopped_at?->toIso8601String(),
            ],
        ]);
    }

    public function uploadSegment(
        UploadRecSegmentRequest $request,
        Game $game,
        RecRecorderSession $session,
    ): JsonResponse {
        $this->assertSessionGame($game, $session);

        $file = $request->file('segment') ?? $request->file('video');

        $segment = $this->segments->announceAndStore(
            $session,
            $this->sessionToken($request),
            $request->validated(),
            $file,
        );

        return response()->json([
            'segment' => [
                'uuid' => $segment->uuid,
                'sequence' => $segment->sequence,
                'idempotency_key' => $segment->idempotency_key,
                'status' => $segment->status->value,
                'bytes' => $segment->bytes,
                'checksum_sha256' => $segment->checksum_sha256,
            ],
        ], 201);
    }

    public function pendingSaves(Request $request, Game $game, RecRecorderSession $session): JsonResponse
    {
        $this->assertSessionGame($game, $session);
        $this->sessions->assertToken($session, $this->sessionToken($request));

        $afterId = $request->integer('after_id') ?: null;
        $targets = $this->saves->pendingForSession($session, $afterId);

        return response()->json([
            'pending' => collect($targets)->map(fn ($target) => [
                'id' => $target->id,
                'save_request_uuid' => $target->saveRequest?->uuid,
                'camera_tag' => $target->camera_tag,
                'status' => $target->status->value,
                'expected_from' => $target->expected_from?->toIso8601String(),
                'expected_until' => $target->expected_until?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function ackSave(
        Request $request,
        Game $game,
        RecRecorderSession $session,
        RecSaveRequest $saveRequest,
    ): JsonResponse {
        $this->assertSessionGame($game, $session);
        $this->assertSaveGame($game, $saveRequest);
        $this->sessions->assertToken($session, $this->sessionToken($request));

        $target = $this->saves->acknowledge($saveRequest, $session, $request->all());

        return response()->json([
            'target' => [
                'id' => $target->id,
                'status' => $target->status->value,
                'acknowledged_at' => $target->acknowledged_at?->toIso8601String(),
            ],
        ]);
    }

    public function createSave(CreateRecSaveRequest $request, Game $game): JsonResponse
    {
        try {
            $result = $this->saves->create(
                $game,
                $request->user(),
                $request->validated('capture_scope') ?? 'all',
                $request->validated('idempotency_key'),
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $saveRequest = $result['save_request'];

        return response()->json([
            'save_request' => [
                'id' => $saveRequest->id,
                'uuid' => $saveRequest->uuid,
                'capture_scope' => $saveRequest->capture_scope,
                'status' => $saveRequest->status->value,
                'triggered_at' => $saveRequest->triggered_at?->toIso8601String(),
                'capture_from' => $saveRequest->capture_from?->toIso8601String(),
                'capture_until' => $saveRequest->capture_until?->toIso8601String(),
                'expected_count' => $saveRequest->expected_count,
                'targets' => $saveRequest->targets->map(fn ($target) => [
                    'id' => $target->id,
                    'camera_tag' => $target->camera_tag,
                    'status' => $target->status->value,
                    'segments_received' => $target->segments_received,
                ])->values(),
            ],
        ], 201);
    }

    public function showSave(Game $game, RecSaveRequest $saveRequest): JsonResponse
    {
        $this->assertSaveGame($game, $saveRequest);

        $saveRequest->load(['targets.clip', 'triggeredBy']);

        return response()->json([
            'save_request' => [
                'id' => $saveRequest->id,
                'uuid' => $saveRequest->uuid,
                'capture_scope' => $saveRequest->capture_scope,
                'status' => $saveRequest->status?->value ?? 'requested',
                'triggered_by' => $saveRequest->triggeredBy?->name,
                'triggered_at' => ($saveRequest->triggered_at ?? $saveRequest->created_at)?->toIso8601String(),
                'capture_from' => $saveRequest->capture_from?->toIso8601String(),
                'capture_until' => $saveRequest->capture_until?->toIso8601String(),
                'expected_count' => $saveRequest->expected_count,
                'ready_count' => $saveRequest->ready_count,
                'failed_count' => $saveRequest->failed_count,
                'targets' => $saveRequest->targets->map(fn ($target) => [
                    'id' => $target->id,
                    'camera_tag' => $target->camera_tag,
                    'status' => $target->status->value,
                    'segments_received' => $target->segments_received,
                    'clip' => $target->clip ? [
                        'id' => $target->clip->id,
                        'status' => $target->clip->status?->value,
                        'url' => $target->clip->url,
                    ] : null,
                ])->values(),
            ],
        ]);
    }

    public function recoveryRequests(Request $request, Game $game, RecRecorderSession $session): JsonResponse
    {
        $this->assertSessionGame($game, $session);
        $this->sessions->assertToken($session, $this->sessionToken($request));

        $targets = $this->saves->pendingForSession($session);

        return response()->json([
            'recovery_requests' => collect($targets)
                ->filter(fn ($target) => ($target->segments_missing ?? 0) > 0)
                ->map(fn ($target) => [
                    'save_request_uuid' => $target->saveRequest?->uuid,
                    'camera_tag' => $target->camera_tag,
                    'expected_from' => $target->expected_from?->toIso8601String(),
                    'expected_until' => $target->expected_until?->toIso8601String(),
                    'segments_missing' => $target->segments_missing,
                ])
                ->values(),
        ]);
    }

    public function segmentStatus(Request $request, Game $game, RecRecorderSession $session): JsonResponse
    {
        $this->assertSessionGame($game, $session);
        $this->sessions->assertToken($session, $this->sessionToken($request));

        $from = $request->integer('from_sequence');
        $to = $request->integer('to_sequence');

        $query = $session->segments()->orderBy('sequence');

        if ($request->filled('from_sequence')) {
            $query->where('sequence', '>=', $from);
        }

        if ($request->filled('to_sequence')) {
            $query->where('sequence', '<=', $to);
        }

        $segments = $query->limit(200)->get();

        return response()->json([
            'segments' => $segments->map(fn ($segment) => [
                'uuid' => $segment->uuid,
                'sequence' => $segment->sequence,
                'status' => $segment->status->value,
                'idempotency_key' => $segment->idempotency_key,
            ])->values(),
        ]);
    }

    private function sessionToken(Request $request): string
    {
        return (string) ($request->header('X-REC-Token') ?: $request->input('token', ''));
    }

    private function assertSessionGame(Game $game, RecRecorderSession $session): void
    {
        if ($session->game_id !== $game->id) {
            abort(404);
        }
    }

    private function assertSaveGame(Game $game, RecSaveRequest $saveRequest): void
    {
        if ($saveRequest->game_id !== $game->id) {
            abort(404);
        }
    }
}
