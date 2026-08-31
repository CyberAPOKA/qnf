<?php

namespace App\WhatsApp;

use App\Support\PhoneNumber;
use App\WhatsApp\Enums\WhatsAppCommandType;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppCommandRateLimiter
{
    public function tooManyAttempts(WhatsAppCommandType $type, string $phone, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return false;
        }

        return RateLimiter::tooManyAttempts($this->key($type, $phone), 1);
    }

    public function hit(WhatsAppCommandType $type, string $phone, bool $isAdmin): void
    {
        if ($isAdmin) {
            return;
        }

        RateLimiter::hit($this->key($type, $phone), $this->decaySeconds($type));
    }

    public function key(WhatsAppCommandType $type, string $phone): string
    {
        if ($type === WhatsAppCommandType::Commands) {
            return 'whatsapp:commands:global';
        }

        if ($type === WhatsAppCommandType::Lineup) {
            return 'whatsapp:lineup:global';
        }

        return 'whatsapp:'.$type->rateLimitBucket().':'.$phone;
    }

    public function decaySeconds(WhatsAppCommandType $type): int
    {
        if ($type === WhatsAppCommandType::Commands) {
            return (int) config('services.whatsapp.commands_global_cooldown_seconds', 3600);
        }

        if ($type === WhatsAppCommandType::Lineup) {
            return (int) config('services.whatsapp.lineup_cooldown_seconds', 3600);
        }

        return (int) config('services.whatsapp.command_cooldown_seconds', 3600);
    }

    public function isUnlimited(?string ...$phones): bool
    {
        $configured = config('services.whatsapp.lineup_unlimited_phone', '555199304836');

        if (is_int($configured) || is_float($configured)) {
            $configured = (string) $configured;
        }

        if (! is_string($configured) || $configured === '') {
            return false;
        }

        foreach ($phones as $phone) {
            if (PhoneNumber::sameLastEight($configured, $phone)) {
                return true;
            }
        }

        return false;
    }
}
