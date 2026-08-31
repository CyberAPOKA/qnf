<?php

namespace App\WhatsApp\Commands;

use App\Models\User;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\Data\WhatsAppCommandResult;
use App\WhatsApp\WhatsAppCommandMessages;
use App\WhatsApp\WhatsAppCommandRateLimiter;

class HelpCommand implements WhatsAppCommand
{
    public function __construct(
        private readonly WhatsAppCommandRateLimiter $rateLimiter,
    ) {}

    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): WhatsAppCommandResult
    {
        $isAdmin = $sender->role === 'admin'
            || $this->rateLimiter->isUnlimited($message->authorPhone, $message->authorId);

        return WhatsAppCommandResult::text(WhatsAppCommandMessages::help($isAdmin));
    }
}
