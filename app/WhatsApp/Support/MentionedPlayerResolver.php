<?php

namespace App\WhatsApp\Support;

use App\Models\User;
use App\Support\PhoneNumber;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;

class MentionedPlayerResolver
{
    public function resolve(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command): ?User
    {
        foreach ($message->mentionPhones() as $phone) {
            $user = PhoneNumber::findUser($phone);

            if ($user) {
                return $user;
            }
        }

        if ($command->argument) {
            return PhoneNumber::findUser($command->argument);
        }

        return null;
    }

    public function hasTargetHint(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command): bool
    {
        if ($message->mentionPhones() !== []) {
            return true;
        }

        return PhoneNumber::digits($command->argument) !== '';
    }
}
