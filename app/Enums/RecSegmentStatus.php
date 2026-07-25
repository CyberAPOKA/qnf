<?php

namespace App\Enums;

enum RecSegmentStatus: string
{
    case Announced = 'announced';
    case Uploading = 'uploading';
    case Received = 'received';
    case Verified = 'verified';
    case Pinned = 'pinned';
    case Expired = 'expired';
    case Failed = 'failed';
}
