<?php

namespace App\Enums;

enum RecSaveRequestStatus: string
{
    case Requested = 'requested';
    case Collecting = 'collecting';
    case Processing = 'processing';
    case Partial = 'partial';
    case Ready = 'ready';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
