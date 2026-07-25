<?php

namespace App\Services\Rec;

use Illuminate\Support\Facades\Cache;

class RecRecorderLeaseService
{
    public function cacheKey(int $gameId, string $cameraTag): string
    {
        return "rec:game:{$gameId}:camera:{$cameraTag}";
    }

    /**
     * @param  array{session_uuid: string, user_id: int, camera_tag: string}  $payload
     */
    public function acquire(int $gameId, string $cameraTag, array $payload, int $ttlSeconds): bool
    {
        $key = $this->cacheKey($gameId, $cameraTag);

        if (Cache::add($key, $payload, now()->addSeconds($ttlSeconds))) {
            return true;
        }

        $current = Cache::get($key);

        if (! is_array($current)) {
            return Cache::add($key, $payload, now()->addSeconds($ttlSeconds));
        }

        return ($current['session_uuid'] ?? null) === ($payload['session_uuid'] ?? null)
            && $this->renew($gameId, $cameraTag, $payload['session_uuid'], $ttlSeconds);
    }

    public function renew(int $gameId, string $cameraTag, string $sessionUuid, int $ttlSeconds): bool
    {
        $key = $this->cacheKey($gameId, $cameraTag);
        $lockKey = "{$key}:lock";

        $lock = Cache::lock($lockKey, 5);

        try {
            if (! $lock->block(3)) {
                return false;
            }

            $current = Cache::get($key);

            if (! is_array($current) || ($current['session_uuid'] ?? null) !== $sessionUuid) {
                return false;
            }

            Cache::put($key, $current, now()->addSeconds($ttlSeconds));

            return true;
        } finally {
            optional($lock)->release();
        }
    }

    public function release(int $gameId, string $cameraTag, string $sessionUuid): bool
    {
        $key = $this->cacheKey($gameId, $cameraTag);
        $lockKey = "{$key}:lock";

        $lock = Cache::lock($lockKey, 5);

        try {
            if (! $lock->block(3)) {
                return false;
            }

            $current = Cache::get($key);

            if (! is_array($current) || ($current['session_uuid'] ?? null) !== $sessionUuid) {
                return false;
            }

            Cache::forget($key);

            return true;
        } finally {
            optional($lock)->release();
        }
    }

    public function holder(int $gameId, string $cameraTag): ?array
    {
        $current = Cache::get($this->cacheKey($gameId, $cameraTag));

        return is_array($current) ? $current : null;
    }
}
