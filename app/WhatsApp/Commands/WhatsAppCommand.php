<?php

namespace App\WhatsApp\Commands;

use App\Models\User;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;

interface WhatsAppCommand
{
    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): string;
}
