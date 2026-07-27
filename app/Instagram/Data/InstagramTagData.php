<?php

namespace App\Instagram\Data;

readonly class InstagramTagData
{
    public function __construct(
        public string $username,
        public float $x = 0.5,
        public float $y = 0.5,
        public ?int $userId = null,
    ) {}

    public function toApiArray(): array
    {
        return [
            'username' => $this->username,
            'x' => round(max(0, min(1, $this->x)), 4),
            'y' => round(max(0, min(1, $this->y)), 4),
        ];
    }
}
