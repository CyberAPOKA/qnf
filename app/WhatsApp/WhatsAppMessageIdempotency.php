<?php

namespace App\WhatsApp;

use Illuminate\Support\Facades\Cache;

class WhatsAppMessageIdempotency
{
    public function claim(string $messageId): bool
    {
        if ($messageId === '') {
            return true;
        }

        $ttl = (int) config('services.whatsapp.idempotency_ttl_seconds', 86400);

        return Cache::add($this->key($messageId), 1, $ttl);
    }

    public function key(string $messageId): string
    {
        return 'whatsapp:processed:'.$messageId;
    }
}
