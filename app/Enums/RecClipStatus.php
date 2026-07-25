<?php

namespace App\Enums;

enum RecClipStatus: string
{
    case Pending = 'pending';
    case RawReady = 'raw_ready';
    case Processing = 'processing';
    case PreviewReady = 'preview_ready';
    case Ready = 'ready';
    case Failed = 'failed';
}
