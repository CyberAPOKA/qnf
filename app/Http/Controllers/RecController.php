<?php

namespace App\Http\Controllers;

use App\Events\ClipReady;
use App\Events\RecorderJoined;
use App\Events\RecorderLeft;
use App\Events\SaveClipRequested;
use App\Models\Game;
use App\Models\RecSaveRequest;
use App\Services\RecSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecController extends Controller
{
    public function __construct(
        private readonly RecSessionService $recSession,
    ) {}

    public function show(Request $request, Game $game): Response
    {
        return Inertia::render('Rec', [
            'game' => [
                'id' => $game->id,
                'date' => $game->date?->format('d/m/Y'),
                'round' => $game->round,
                'status' => $game->status->value,
            ],
            'recorders' => $this->recSession->listRecorders($game->id),
            'recent_saves' => $this->recSession->recentSaveRequests($game),
            'buffer_seconds' => $this->recSession->bufferSeconds(),
            'current_user_id' => $request->user()->id,
            'current_user_name' => $request->user()->name,
        ]);
    }

    public function start(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'recorder_id' => ['required', 'string', 'max:64'],
        ]);

        $recorders = $this->recSession->registerRecorder(
            $game,
            $request->user(),
            $validated['recorder_id'],
        );

        rescue(
            fn () => broadcast(new RecorderJoined($game->id, $recorders))->toOthers(),
            report: false,
        );

        return response()->json([
            'recorders' => $recorders,
            'buffer_seconds' => $this->recSession->bufferSeconds(),
        ]);
    }

    public function heartbeat(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'recorder_id' => ['required', 'string', 'max:64'],
        ]);

        $recorder = $this->recSession->heartbeat($game, $validated['recorder_id']);

        if (! $recorder) {
            return response()->json(['message' => 'Gravador não registrado.'], 404);
        }

        return response()->json(['recorder' => $recorder]);
    }

    public function stop(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'recorder_id' => ['required', 'string', 'max:64'],
        ]);

        $recorders = $this->recSession->unregisterRecorder($game, $validated['recorder_id']);

        rescue(
            fn () => broadcast(new RecorderLeft($game->id, $validated['recorder_id'], $recorders))->toOthers(),
            report: false,
        );

        return response()->json(['recorders' => $recorders]);
    }

    public function save(Request $request, Game $game): JsonResponse
    {
        $recorders = $this->recSession->listRecorders($game->id);

        if (count($recorders) === 0) {
            return response()->json(['message' => 'Nenhuma câmera gravando no momento.'], 422);
        }

        $saveRequest = $this->recSession->createSaveRequest($game, $request->user());

        rescue(
            fn () => broadcast(new SaveClipRequested(
                $game->id,
                $saveRequest->uuid,
                $saveRequest->id,
                $request->user()->name,
                count($recorders),
            ))->toOthers(),
            report: false,
        );

        return response()->json([
            'save_request' => $this->recSession->serializeSaveRequest(
                $saveRequest->load('triggeredBy'),
            ),
            'expected_recorders' => count($recorders),
        ]);
    }

    public function upload(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'save_request_uuid' => ['required', 'string', 'uuid'],
            'recorder_id' => ['required', 'string', 'max:64'],
            'video' => ['required', 'file', 'mimetypes:video/webm,video/mp4,video/quicktime', 'max:51200'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $saveRequest = RecSaveRequest::query()
            ->where('game_id', $game->id)
            ->where('uuid', $validated['save_request_uuid'])
            ->firstOrFail();

        $path = $request->file('video')->store(
            "rec/{$game->id}/{$saveRequest->uuid}",
            'public',
        );

        $clip = $this->recSession->storeClip(
            $saveRequest,
            $request->user(),
            $validated['recorder_id'],
            $path,
            (int) ($validated['duration_seconds'] ?? $this->recSession->bufferSeconds()),
        );

        $clip->load('user');
        $clipPayload = $this->recSession->serializeClip($clip);

        rescue(
            fn () => broadcast(new ClipReady($game->id, $saveRequest->uuid, $clipPayload))->toOthers(),
            report: false,
        );

        return response()->json(['clip' => $clipPayload]);
    }
}
