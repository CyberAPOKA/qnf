<?php

namespace App\Services;

use App\Enums\Position;
use App\Models\User;

class ProfileService
{
    public function updatePosition(User $user, string $position): void
    {
        abort_if($user->position === Position::GOALKEEPER, 403);

        $user->update(['position' => $position]);
    }
}
