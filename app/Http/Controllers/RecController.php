<?php

namespace App\Http\Controllers;

use App\Events\ClipReady;
use App\Events\RecorderJoined;
use App\Events\RecorderLeft;
use App\Events\SaveClipRequested;
use App\Models\Game;
use App\Models\RecSaveRequest;
use App\Services\RecClipNormalizeService;
use App\Services\RecSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class RecController extends Controller
{
    public function __construct(
        private readonly RecSessionService $recSession,
        private readonly RecClipNormalizeService $clipNormalize,
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
            'camera_tag' => ['required', 'string', 'in:A1,A2,B1,B2'],
        ]);

        $recorders = $this->recSession->registerRecorder(
            $game,
            $request->user(),
            $validated['recorder_id'],
            $validated['camera_tag'],
        );

        Log::info('REC start', [
            'game_id' => $game->id,
            'user_id' => $request->user()->id,
            'recorder_id' => $validated['recorder_id'],
            'camera_tag' => $validated['camera_tag'],
            'recorders' => count($recorders),
        ]);

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

        Log::info('REC stop', [
            'game_id' => $game->id,
            'user_id' => $request->user()->id,
            'recorder_id' => $validated['recorder_id'],
            'recorders' => count($recorders),
        ]);

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
            Log::warning('REC save rejected: no recorders', [
                'game_id' => $game->id,
                'user_id' => $request->user()->id,
            ]);

            return response()->json(['message' => 'Nenhuma câmera gravando no momento.'], 422);
        }

        $saveRequest = $this->recSession->createSaveRequest($game, $request->user());

        Log::info('REC save requested', [
            'game_id' => $game->id,
            'user_id' => $request->user()->id,
            'uuid' => $saveRequest->uuid,
            'expected_recorders' => count($recorders),
        ]);

        // Broadcast to ALL devices (including trigger). Recording clients
        // dedupe uploads locally; trigger that is also recording uploads once.
        $broadcastOk = rescue(
            function () use ($game, $saveRequest, $request, $recorders) {
                broadcast(new SaveClipRequested(
                    $game->id,
                    $saveRequest->uuid,
                    $saveRequest->id,
                    $request->user()->name,
                    count($recorders),
                ));

                return true;
            },
            false,
            report: false,
        );

        if (! $broadcastOk) {
            Log::warning('REC SaveClipRequested broadcast failed', [
                'game_id' => $game->id,
                'uuid' => $saveRequest->uuid,
            ]);
        }

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
            'camera_tag' => ['nullable', 'string', 'in:A1,A2,B1,B2'],
            'video' => ['required', 'file', 'max:51200'],
            'video_prefix' => ['nullable', 'file', 'max:51200'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $file = $request->file('video');

        if ($file && $file->getSize() < 1) {
            Log::warning('REC upload empty file', [
                'game_id' => $game->id,
                'uuid' => $validated['save_request_uuid'],
            ]);

            return response()->json(['message' => 'Arquivo de vídeo vazio.'], 422);
        }

        $saveRequest = RecSaveRequest::query()
            ->where('game_id', $game->id)
            ->where('uuid', $validated['save_request_uuid'])
            ->firstOrFail();

        $existing = $saveRequest->clips()
            ->where('recorder_id', $validated['recorder_id'])
            ->first();

        if ($existing) {
            $existing->load('user');

            Log::info('REC upload ignored (duplicate)', [
                'game_id' => $game->id,
                'uuid' => $saveRequest->uuid,
                'clip_id' => $existing->id,
            ]);

            return response()->json([
                'clip' => $this->recSession->serializeClip($existing),
            ]);
        }

        $directory = "rec/{$game->id}/{$saveRequest->uuid}";
        $path = $file->store($directory, 'public');
        $keepSeconds = $this->recSession->bufferSeconds();
        $alreadyMerged = false;

        $prefix = $request->file('video_prefix');
        if ($prefix && $prefix->getSize() > 0) {
            $prefixPath = $prefix->store($directory, 'public');
            $currentAbsolute = Storage::disk('public')->path($path);
            $prefixAbsolute = Storage::disk('public')->path($prefixPath);
            $mergedRelative = $directory.'/merged_'.uniqid('', true).'.webm';
            $mergedAbsolute = Storage::disk('public')->path($mergedRelative);

            $merged = $this->clipNormalize->mergeAndTrim(
                $prefixAbsolute,
                $currentAbsolute,
                $mergedAbsolute,
                $keepSeconds,
            );

            Storage::disk('public')->delete($prefixPath);

            if ($merged && is_file($mergedAbsolute)) {
                Storage::disk('public')->delete($path);
                $path = $mergedRelative;
                $alreadyMerged = true;
            } else {
                Log::warning('REC prefix merge failed, normalizing current only', [
                    'uuid' => $saveRequest->uuid,
                ]);
            }
        }

        // mergeAndTrim already re-encodes + trims; skip a second encode pass.
        if ($alreadyMerged) {
            $absolute = Storage::disk('public')->path($path);
            $duration = $this->clipNormalize->probeDurationSeconds($absolute) ?? $keepSeconds;
            $normalized = [
                'duration_seconds' => (int) max(1, round($duration)),
                'bytes' => (int) (@filesize($absolute) ?: 0),
            ];
        } else {
            $normalized = $this->clipNormalize->normalize($path, $keepSeconds);
        }

        $durationSeconds = (int) ($normalized['duration_seconds']
            ?? $validated['duration_seconds']
            ?? $keepSeconds);

        $clip = $this->recSession->storeClip(
            $saveRequest,
            $request->user(),
            $validated['recorder_id'],
            $path,
            $durationSeconds,
            $validated['camera_tag'] ?? null,
        );

        $clip->load('user');
        $clipPayload = $this->recSession->serializeClip($clip);

        Log::info('REC upload ok', [
            'game_id' => $game->id,
            'uuid' => $saveRequest->uuid,
            'clip_id' => $clip->id,
            'user_id' => $request->user()->id,
            'camera_tag' => $validated['camera_tag'] ?? null,
            'bytes' => $normalized['bytes'] ?? $file->getSize(),
            'duration' => $durationSeconds,
            'normalized' => (bool) $normalized,
            'mime' => $file->getMimeType(),
        ]);

        $broadcastOk = rescue(
            function () use ($game, $saveRequest, $clipPayload) {
                broadcast(new ClipReady($game->id, $saveRequest->uuid, $clipPayload));

                return true;
            },
            false,
            report: false,
        );

        if (! $broadcastOk) {
            Log::warning('REC ClipReady broadcast failed', [
                'game_id' => $game->id,
                'uuid' => $saveRequest->uuid,
                'clip_id' => $clip->id,
            ]);
        }

        return response()->json(['clip' => $clipPayload]);
    }
}
