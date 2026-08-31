<?php

namespace App\WhatsApp;

use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\Enums\WhatsAppCommandType;

class WhatsAppCommandParser
{
    public function parse(IncomingWhatsAppMessage $message): ?ParsedWhatsAppCommand
    {
        $body = trim($message->body);

        if ($body === '' || ! str_starts_with($body, '/')) {
            return null;
        }

        $parts = preg_split('/\s+/u', $body, 2) ?: [];
        $alias = $parts[0] ?? '';
        $type = WhatsAppCommandType::fromAlias($alias);

        if (! $type) {
            return null;
        }

        $argument = isset($parts[1]) ? trim($parts[1]) : null;

        return new ParsedWhatsAppCommand(
            type: $type,
            argument: $argument === '' ? null : $argument,
        );
    }
}
