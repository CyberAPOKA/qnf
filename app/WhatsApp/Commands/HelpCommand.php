<?php

namespace App\WhatsApp\Commands;

use App\Models\User;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\Data\WhatsAppCommandResult;
use App\WhatsApp\WhatsAppCommandMessages;

class HelpCommand implements WhatsAppCommand
{
    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): WhatsAppCommandResult
    {
        return WhatsAppCommandResult::text(WhatsAppCommandMessages::help($sender->role === 'admin'));
    }
}
