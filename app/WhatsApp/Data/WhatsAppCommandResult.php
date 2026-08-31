<?php

namespace App\WhatsApp\Data;

readonly class WhatsAppCommandResult
{
    public function __construct(
        public ?string $reply = null,
        public ?string $audioPath = null,
        public bool $cleanupAudio = false,
    ) {}

    public static function silent(): self
    {
        return new self;
    }

    public static function text(string $reply): self
    {
        return new self(reply: $reply);
    }

    public static function audio(string $path, ?string $reply = null): self
    {
        return new self(reply: $reply, audioPath: $path, cleanupAudio: true);
    }
}
