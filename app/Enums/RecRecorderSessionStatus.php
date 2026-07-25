<?php

namespace App\Enums;

enum RecRecorderSessionStatus: string
{
    case Starting = 'starting';
    case Recording = 'recording';
    case Degraded = 'degraded';
    case Reconnecting = 'reconnecting';
    case Stopped = 'stopped';
    case Expired = 'expired';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return match ($this) {
            self::Starting, self::Recording, self::Degraded, self::Reconnecting => true,
            default => false,
        };
    }
}
