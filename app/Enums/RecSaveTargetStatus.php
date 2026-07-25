<?php

namespace App\Enums;

enum RecSaveTargetStatus: string
{
    case WaitingAck = 'waiting_ack';
    case Collecting = 'collecting';
    case RawReady = 'raw_ready';
    case Processing = 'processing';
    case PreviewReady = 'preview_ready';
    case Ready = 'ready';
    case Partial = 'partial';
    case Failed = 'failed';
    case CameraOffline = 'camera_offline';
}
