<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DraftPickResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'round' => $this->round,
            'pick_in_round' => $this->pick_in_round,
            'team_color' => $this->team_color->value,
            'picked_user' => [
                'id' => $this->pickedUser->id,
                'name' => $this->pickedUser->name,
            ],
            'picked_at' => $this->picked_at?->toIso8601String(),
        ];
    }
}
