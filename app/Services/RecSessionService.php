<?php

namespace App\Services;

use App\Models\Game;
use App\Models\RecClip;
use App\Models\RecSaveRequest;
use Illuminate\Support\Facades\Cache;

class RecSessionService
{
    private const SCOPE_CAMERA_TAGS = [
        'all' => ['A1', 'A2', 'B1', 'B2'],
        'left' => ['A1', 'B1'],
        'right' => ['A2', 'B2'],
    ];

    public function bufferSeconds(): int
    {
        return (int) config('rec.buffer_seconds', 30);
    }

    /**
     * @return list<string>
     */
    public function cameraTagsForScope(string $captureScope): array
    {
        return self::SCOPE_CAMERA_TAGS[$captureScope]
            ?? self::SCOPE_CAMERA_TAGS['all'];
    }

    /**
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

    public function saveDebounceMilliseconds(): int
    {
        return (int) config('rec.save_debounce_milliseconds', 800);
    }

    public function saveCooldownSeconds(): int
    {
        return $this->scopeCooldownSeconds();
    }

    public function recentSaveRequests(Game $game, int $limit = 10): array
    {
        return RecSaveRequest::query()
            ->where('game_id', $game->id)
            ->with(['clips.user', 'triggeredBy', 'targets.clip'])
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
            'status' => $request->status?->value ?? null,
            'triggered_by' => $request->triggeredBy?->name,
            'triggered_at' => ($request->triggered_at ?? $request->created_at)?->toIso8601String(),
            'targets' => $request->relationLoaded('targets')
                ? $request->targets->map(fn ($target) => [
                    'id' => $target->id,
                    'camera_tag' => $target->camera_tag,
                    'status' => $target->status?->value,
                    'segments_received' => $target->segments_received,
                    'clip' => $target->clip ? $this->serializeClip($target->clip) : null,
                ])->values()->all()
                : [],
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
            'status' => $clip->status?->value,
        ];
    }
}
