<?php

namespace App\WhatsApp\Data;

use App\WhatsApp\Enums\WhatsAppCommandType;

readonly class ParsedWhatsAppCommand
{
    public function __construct(
        public WhatsAppCommandType $type,
        public ?string $argument = null,
    ) {}
}
