<?php

namespace App\Services\FishAudio;

readonly class GeneratedAudio
{
    public function __construct(
        public string $contents,
        public string $format = 'mp3',
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
