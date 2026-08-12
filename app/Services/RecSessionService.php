<?php

namespace App\Services;

use App\Models\Game;
use App\Models\RecClip;
use App\Models\RecSaveRequest;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecSessionService
{
    private const RECORDER_TTL_SECONDS = 45;

    private const BUFFER_SECONDS = 30;

    private const SAVE_COOLDOWN_SECONDS = 10;

    public function bufferSeconds(): int
    {
        return self::BUFFER_SECONDS;
    }

    public function saveCooldownSeconds(): int
    {
        return self::SAVE_COOLDOWN_SECONDS;
    }

    public function registerRecorder(Game $game, User $user, string $recorderId, string $cameraTag): array
    {
        $recorders = $this->getRecorders($game->id);

        $recorders[$recorderId] = [
            'recorder_id' => $recorderId,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'camera_tag' => $cameraTag,
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

    /**
     * First SAVE in the cooldown window wins; later requests are ignored.
     *
     * @return array{created: bool, save_request: RecSaveRequest|null, retry_after: int}
     */
    public function createSaveRequest(Game $game, User $user): array
    {
        $lock = Cache::lock("rec:game:{$game->id}:save", 8);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return $this->ignoredSaveResult($game);
        }

        try {
            $recent = $this->recentSaveWithinCooldown($game);

            if ($recent) {
                return $this->ignoredSaveResult($game, $recent);
            }

            $saveRequest = RecSaveRequest::create([
                'game_id' => $game->id,
                'triggered_by' => $user->id,
                'uuid' => (string) Str::uuid(),
            ]);

            return [
                'created' => true,
                'save_request' => $saveRequest,
                'retry_after' => self::SAVE_COOLDOWN_SECONDS,
            ];
        } finally {
            $lock->release();
        }
    }

    private function recentSaveWithinCooldown(Game $game): ?RecSaveRequest
    {
        return RecSaveRequest::query()
            ->where('game_id', $game->id)
            ->where('created_at', '>=', now()->subSeconds(self::SAVE_COOLDOWN_SECONDS))
            ->latest('id')
            ->first();
    }

    /**
     * @return array{created: bool, save_request: RecSaveRequest|null, retry_after: int}
     */
    private function ignoredSaveResult(Game $game, ?RecSaveRequest $recent = null): array
    {
        $recent ??= $this->recentSaveWithinCooldown($game);
        $elapsed = $recent?->created_at
            ? (int) $recent->created_at->diffInSeconds(now())
            : 0;

        return [
            'created' => false,
            'save_request' => $recent,
            'retry_after' => max(1, self::SAVE_COOLDOWN_SECONDS - $elapsed),
        ];
    }

    public function storeClip(
        RecSaveRequest $saveRequest,
        User $user,
        string $recorderId,
        string $filePath,
        int $durationSeconds = self::BUFFER_SECONDS,
        ?string $cameraTag = null,
    ): RecClip {
        return RecClip::create([
            'rec_save_request_id' => $saveRequest->id,
            'game_id' => $saveRequest->game_id,
            'user_id' => $user->id,
            'recorder_id' => $recorderId,
            'camera_tag' => $cameraTag,
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
            'camera_tag' => $clip->camera_tag,
            'user_name' => $clip->user?->name,
            'url' => $clip->url,
            'duration_seconds' => $clip->duration_seconds,
        ];
    }

    /**
     * Remove REC rows and stored videos for a single game. Leaves the game itself intact.
     *
     * @return array{clips: int, saves: int}
     */
    public function clearGame(Game $game): array
    {
        $gameId = $game->id;
        $clipCount = RecClip::query()->where('game_id', $gameId)->count();
        $saveCount = RecSaveRequest::query()->where('game_id', $gameId)->count();
        $requestIds = RecSaveRequest::query()->where('game_id', $gameId)->pluck('id');

        if (Schema::hasTable('rec_operational_events')) {
            DB::table('rec_operational_events')->where('game_id', $gameId)->delete();
        }

        if (Schema::hasTable('rec_outbox_events')) {
            DB::table('rec_outbox_events')->where('game_id', $gameId)->delete();
        }

        if (Schema::hasTable('rec_save_targets')) {
            $targetIds = DB::table('rec_save_targets')
                ->whereIn('rec_save_request_id', $requestIds)
                ->pluck('id');

            if (Schema::hasTable('rec_save_target_segments') && $targetIds->isNotEmpty()) {
                DB::table('rec_save_target_segments')->whereIn('rec_save_target_id', $targetIds)->delete();
            }

            DB::table('rec_save_targets')->whereIn('rec_save_request_id', $requestIds)->delete();
        }

        RecClip::query()->where('game_id', $gameId)->delete();
        RecSaveRequest::query()->where('game_id', $gameId)->delete();

        if (Schema::hasTable('rec_segments')) {
            DB::table('rec_segments')->where('game_id', $gameId)->delete();
        }

        if (Schema::hasTable('rec_recorder_sessions')) {
            DB::table('rec_recorder_sessions')->where('game_id', $gameId)->delete();
        }

        Storage::disk('public')->deleteDirectory("rec/{$gameId}");
        Cache::forget($this->cacheKey($gameId));
        Cache::lock("rec:game:{$gameId}:save")->forceRelease();

        return [
            'clips' => $clipCount,
            'saves' => $saveCount,
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
