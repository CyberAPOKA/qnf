<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GamePlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'phone' => $this->user->phone,
            'position' => $this->user->position->value,
            'position_label' => $this->user->position->label(),
            'guest' => $this->user->guest,
            'photo_front' => $this->user->photo_front_url,
            'initial' => $this->user->initial,
            'joined_at' => $this->joined_at?->toIso8601String(),
        ];
    }
}
