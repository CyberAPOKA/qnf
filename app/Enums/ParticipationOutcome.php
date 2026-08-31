<?php

namespace App\Enums;

enum ParticipationOutcome: string
{
    case Joined = 'joined';
    case AlreadyJoined = 'already_joined';
    case Waitlisted = 'waitlisted';
    case AlreadyWaitlisted = 'already_waitlisted';
    case Quit = 'quit';
    case LeftWaitlist = 'left_waitlist';
    case Removed = 'removed';
    case RemovedFromWaitlist = 'removed_from_waitlist';
}
