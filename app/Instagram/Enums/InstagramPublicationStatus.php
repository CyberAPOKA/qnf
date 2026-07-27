<?php

namespace App\Instagram\Enums;

enum InstagramPublicationStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Rendering = 'rendering';
    case Validating = 'validating';
    case CreatingContainers = 'creating_containers';
    case WaitingContainers = 'waiting_containers';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case DryRunCompleted = 'dry_run_completed';
    case Deferred = 'deferred';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Published,
            self::Failed,
            self::Cancelled,
            self::DryRunCompleted,
        ], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Preparing,
            self::Rendering,
            self::Validating,
            self::CreatingContainers,
            self::WaitingContainers,
            self::Publishing,
            self::Deferred,
            self::Failed,
        ], true);
    }
}
