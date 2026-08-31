<?php

namespace App\Support;

use App\Enums\ParticipationOutcome;
use App\Models\User;

readonly class ParticipationResult
{
    public function __construct(
        public ParticipationOutcome $outcome,
        public ?User $promoted = null,
        public ?int $waitlistPosition = null,
        public ?User $target = null,
    ) {}

    public function wasWaitlisted(): bool
    {
        return $this->outcome === ParticipationOutcome::Waitlisted
            || $this->outcome === ParticipationOutcome::AlreadyWaitlisted
            || $this->outcome === ParticipationOutcome::LeftWaitlist
            || $this->outcome === ParticipationOutcome::RemovedFromWaitlist;
    }
}
