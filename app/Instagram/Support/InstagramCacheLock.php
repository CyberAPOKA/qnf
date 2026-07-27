<?php

namespace App\Instagram\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstagramCacheLock
{
    /**
     * @return array{lock: ?Lock, acquired: bool, soft_failed: bool}
     */
    public static function attempt(string $name, int $seconds = 30): array
    {
        foreach (self::storeCandidates() as $store) {
            try {
                $lock = Cache::store($store)->lock($name, $seconds);

                if ($lock->get()) {
                    return [
                        'lock' => $lock,
                        'acquired' => true,
                        'soft_failed' => false,
                    ];
                }

                return [
                    'lock' => null,
                    'acquired' => false,
                    'soft_failed' => false,
                ];
            } catch (Throwable $e) {
                Log::warning('Instagram cache lock store failed', [
                    'store' => $store,
                    'lock' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning('Instagram cache lock unavailable; continuing without lock', [
            'lock' => $name,
        ]);

        return [
            'lock' => null,
            'acquired' => true,
            'soft_failed' => true,
        ];
    }

    public static function release(?Lock $lock): void
    {
        if (! $lock) {
            return;
        }

        try {
            $lock->release();
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return list<string>
     */
    private static function storeCandidates(): array
    {
        $default = (string) config('cache.default', 'database');
        $candidates = [];

        if (! in_array($default, ['file', 'array', 'null'], true)) {
            $candidates[] = $default;
        }

        $candidates[] = 'database';

        return array_values(array_unique($candidates));
    }
}
