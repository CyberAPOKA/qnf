<?php

namespace App\Enums;

enum DraftNarrationStatus: string
{
    case PENDING = 'pending';
    case GENERATING = 'generating';
    case GENERATED = 'generated';
    case SENDING = 'sending';
    case SENT = 'sent';
    case FAILED = 'failed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
