<?php

namespace App\Services;

use App\Models\Game;
use App\Models\RecClip;
use App\Models\RecSaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RecSessionService
{
    private const RECORDER_TTL_SECONDS = 45;

    private const BUFFER_SECONDS = 30;

    public function bufferSeconds(): int
    {
        return self::BUFFER_SECONDS;
    }

    public function registerRecorder(Game $game, User $user, string $recorderId): array
    {
        $recorders = $this->getRecorders($game->id);

        $recorders[$recorderId] = [
            'recorder_id' => $recorderId,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'joined_at' => now()->toIso8601String(),
            'last_heartbeat' => now()->toIso8601String(),
        ];

        $this->putRecorders($game->id, $recorders);

        return array_values($recorders);
    }

    public function heartbeat(Game $game, string $recorderId): ?array
    {
        $recorders = $this->getRecorders($game->id);

        if (! isset($recorders[$recorderId])) {
            return null;
        }

        $recorders[$recorderId]['last_heartbeat'] = now()->toIso8601String();
        $this->putRecorders($game->id, $recorders);

        return $recorders[$recorderId];
    }

    public function unregisterRecorder(Game $game, string $recorderId): array
    {
        $recorders = $this->getRecorders($game->id);
        unset($recorders[$recorderId]);
        $this->putRecorders($game->id, $recorders);

        return array_values($recorders);
    }

    public function listRecorders(int $gameId): array
    {
        $recorders = $this->getRecorders($gameId);
        $now = now();

        $active = [];

        foreach ($recorders as $recorderId => $recorder) {
            $lastHeartbeat = $recorder['last_heartbeat'] ?? $recorder['joined_at'] ?? null;

            if (! $lastHeartbeat) {
                continue;
            }

            if (abs($now->diffInSeconds($lastHeartbeat)) > self::RECORDER_TTL_SECONDS) {
                unset($recorders[$recorderId]);

                continue;
            }

            $active[] = $recorder;
        }

        if (count($active) !== count($recorders)) {
            $this->putRecorders($gameId, $recorders);
        }

        return $active;
    }

    public function createSaveRequest(Game $game, User $user): RecSaveRequest
    {
        return RecSaveRequest::create([
            'game_id' => $game->id,
            'triggered_by' => $user->id,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function storeClip(
        RecSaveRequest $saveRequest,
        User $user,
        string $recorderId,
        string $filePath,
        int $durationSeconds = self::BUFFER_SECONDS,
    ): RecClip {
        return RecClip::create([
            'rec_save_request_id' => $saveRequest->id,
            'game_id' => $saveRequest->game_id,
            'user_id' => $user->id,
            'recorder_id' => $recorderId,
            'file_path' => $filePath,
            'duration_seconds' => $durationSeconds,
        ]);
    }

    public function recentSaveRequests(Game $game, int $limit = 10): array
    {
        return RecSaveRequest::query()
            ->where('game_id', $game->id)
            ->with(['clips.user', 'triggeredBy'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (RecSaveRequest $request) => $this->serializeSaveRequest($request))
            ->all();
    }

    public function serializeSaveRequest(RecSaveRequest $request): array
    {
        return [
            'id' => $request->id,
            'uuid' => $request->uuid,
            'triggered_by' => $request->triggeredBy?->name,
            'triggered_at' => $request->created_at?->toIso8601String(),
            'clips' => $request->clips->map(fn (RecClip $clip) => $this->serializeClip($clip))->values()->all(),
        ];
    }

    public function serializeClip(RecClip $clip): array
    {
        return [
            'id' => $clip->id,
            'recorder_id' => $clip->recorder_id,
            'user_name' => $clip->user?->name,
            'url' => $clip->url,
            'duration_seconds' => $clip->duration_seconds,
        ];
    }

    private function cacheKey(int $gameId): string
    {
        return "rec:game:{$gameId}:recorders";
    }

    private function getRecorders(int $gameId): array
    {
        return Cache::get($this->cacheKey($gameId), []);
    }

    private function putRecorders(int $gameId, array $recorders): void
    {
        Cache::put(
            $this->cacheKey($gameId),
            $recorders,
            now()->addSeconds(self::RECORDER_TTL_SECONDS * 2),
        );
    }
}
