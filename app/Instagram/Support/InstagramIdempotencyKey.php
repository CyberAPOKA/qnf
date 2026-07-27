<?php

namespace App\Instagram\Support;

use App\Instagram\Enums\InstagramPublicationType;
use App\Instagram\Enums\InstagramTriggerType;

class InstagramIdempotencyKey
{
    public static function make(
        InstagramTriggerType $triggerType,
        int|string $triggerId,
        string $triggerVersion,
        InstagramPublicationType $publicationType,
        ?string $suffix = null,
    ): string {
        $parts = [
            $triggerType->value,
            (string) $triggerId,
            $triggerVersion,
            $publicationType->value,
        ];

        if ($suffix !== null && $suffix !== '') {
            $parts[] = $suffix;
        }

        return implode(':', $parts);
    }
}
