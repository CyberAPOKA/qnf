<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    protected ?object $stats = null;

    public function withStats(?object $stats): static
    {
        $this->stats = $stats;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'position' => $this->position->value,
            'position_label' => $this->position->label(),
            'guest' => $this->guest,
            'photo_front' => $this->photo_front_url,
            'initial' => mb_strtoupper(mb_substr($this->name, 0, 1)),
            'games_played' => $this->stats?->games_played ?? 0,
            'total_points' => $this->stats?->total_points ?? 0,
        ];
    }
}
