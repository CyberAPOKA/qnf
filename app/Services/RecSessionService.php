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

    private const SCOPE_CAMERA_TAGS = [
        'all' => ['A1', 'A2', 'B1', 'B2'],
        'left' => ['A1', 'B1'],
        'right' => ['A2', 'B2'],
    ];

    public function bufferSeconds(): int
    {
        return (int) config('rec.buffer_seconds', 30);
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

    public function createSaveRequest(Game $game, User $user, string $captureScope = 'all'): RecSaveRequest
    {
        return RecSaveRequest::create([
            'game_id' => $game->id,
            'triggered_by' => $user->id,
            'uuid' => (string) Str::uuid(),
            'capture_scope' => $captureScope,
        ]);
    }

    public function cameraTagsForScope(string $captureScope): array
    {
        return self::SCOPE_CAMERA_TAGS[$captureScope] ?? self::SCOPE_CAMERA_TAGS['all'];
    }

    public function recordersForScope(array $recorders, string $captureScope): array
    {
        $cameraTags = $this->cameraTagsForScope($captureScope);

        return array_values(array_filter(
            $recorders,
            fn (array $recorder) => in_array($recorder['camera_tag'] ?? null, $cameraTags, true),
        ));
    }

    /**
     * Short debounce against accidental double-clicks (not a global cooldown).
     *
     * @return int Milliseconds left when blocked, or 0 when acquired.
     */
    public function acquireSaveDebounce(int $gameId, ?string $idempotencyKey = null): int
    {
        $debounceMs = (int) config('rec.save_debounce_milliseconds', 800);

        if ($debounceMs <= 0) {
            return 0;
        }

        $key = $idempotencyKey
            ? "rec:game:{$gameId}:save-debounce:{$idempotencyKey}"
            : "rec:game:{$gameId}:save-debounce";

        $expiresAt = now()->addMilliseconds($debounceMs);

        if (Cache::add($key, (int) floor($expiresAt->valueOf()), $expiresAt)) {
            return 0;
        }

        $storedExpiry = (int) Cache::get($key, (int) floor($expiresAt->valueOf()));

        return max(1, $storedExpiry - (int) floor(microtime(true) * 1000));
    }

    /**
     * Locks that a SAVE scope activates.
     *
     * - left  → locks left (middle also blocked via conflict check)
     * - right → locks right
     * - all   → locks all (and therefore both sides)
     *
     * @return list<string>
     */
    public function scopesLockedBy(string $captureScope): array
    {
        return match ($captureScope) {
            'left' => ['left'],
            'right' => ['right'],
            default => ['all', 'left', 'right'],
        };
    }

    /**
     * Locks that block a new SAVE for the given scope.
     *
     * @return list<string>
     */
    public function scopesBlocking(string $captureScope): array
    {
        return match ($captureScope) {
            'left' => ['left', 'all'],
            'right' => ['right', 'all'],
            default => ['left', 'right', 'all'],
        };
    }

    public function scopeCooldownSeconds(): int
    {
        return max(1, (int) config('rec.save_scope_cooldown_seconds', 10));
    }

    private function scopeCooldownKey(int $gameId, string $scope): string
    {
        return "rec:game:{$gameId}:save-scope:{$scope}";
    }

    /**
     * Atomically acquire per-side SAVE locks.
     *
     * Left and right do not block each other. Either side blocks "all".
     * "all" blocks both sides.
     *
     * @return array{ok: bool, retry_after: int, locked_scopes: list<string>, cooldown_seconds: int}
     */
    public function acquireScopeCooldown(int $gameId, string $captureScope): array
    {
        $cooldownSeconds = $this->scopeCooldownSeconds();
        $blocking = $this->scopesBlocking($captureScope);
        $maxRemaining = 0;

        foreach ($blocking as $scope) {
            $storedExpiry = Cache::get($this->scopeCooldownKey($gameId, $scope));

            if (! $storedExpiry) {
                continue;
            }

            $remaining = max(0, (int) $storedExpiry - now()->timestamp);

            if ($remaining > 0) {
                $maxRemaining = max($maxRemaining, $remaining);
            }
        }

        if ($maxRemaining > 0) {
            return [
                'ok' => false,
                'retry_after' => $maxRemaining,
                'locked_scopes' => $this->activeLockedScopes($gameId),
                'cooldown_seconds' => $cooldownSeconds,
            ];
        }

        $expiresAt = now()->addSeconds($cooldownSeconds);
        $lockedScopes = $this->scopesLockedBy($captureScope);

        foreach ($lockedScopes as $scope) {
            Cache::put(
                $this->scopeCooldownKey($gameId, $scope),
                $expiresAt->timestamp,
                $expiresAt,
            );
        }

        return [
            'ok' => true,
            'retry_after' => 0,
            'locked_scopes' => $lockedScopes,
            'cooldown_seconds' => $cooldownSeconds,
        ];
    }

    /**
     * @return list<string>
     */
    public function activeLockedScopes(int $gameId): array
    {
        $locked = [];

        foreach (['left', 'right', 'all'] as $scope) {
            $storedExpiry = Cache::get($this->scopeCooldownKey($gameId, $scope));

            if ($storedExpiry && ((int) $storedExpiry - now()->timestamp) > 0) {
                $locked[] = $scope;
            }
        }

        return $locked;
    }

    /** @deprecated Use acquireScopeCooldown() */
    public function acquireSaveCooldown(int $gameId): int
    {
        $result = $this->acquireScopeCooldown($gameId, 'all');

        return $result['ok'] ? 0 : $result['retry_after'];
    }

    public function saveDebounceMilliseconds(): int
    {
        return (int) config('rec.save_debounce_milliseconds', 800);
    }

    public function saveCooldownSeconds(): int
    {
        return $this->scopeCooldownSeconds();
    }

    public function storeClip(
        RecSaveRequest $saveRequest,
        User $user,
        string $recorderId,
        string $filePath,
        int $durationSeconds = 30,
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
            'capture_scope' => $request->capture_scope ?? 'all',
            'camera_tags' => $this->cameraTagsForScope($request->capture_scope ?? 'all'),
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
