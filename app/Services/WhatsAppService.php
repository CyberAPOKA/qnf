<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private bool $active;
    private string $serviceUrl;
    private ?string $groupId;

    public function __construct()
    {
        $this->active = (bool) config('services.whatsapp.active', false);
        $this->serviceUrl = config('services.whatsapp.url', 'http://127.0.0.1:3001');
        $this->groupId = config('services.whatsapp.group_id');
    }

    public function sendToGroup(string $message): bool
    {
        if (! $this->active) {
            Log::info('[WhatsApp] (inactive) Group message:', ['message' => $message]);

            return false;
        }

        if (! $this->groupId) {
            Log::warning('WhatsApp group ID not configured.');
            return false;
        }

        return $this->send($this->groupId, $message);
    }

    public function sendToPhone(string $phone, string $message): bool
    {
        if (! $this->active) {
            return false;
        }

        $chatId = preg_replace('/\D/', '', $phone) . '@c.us';

        return $this->send($chatId, $message);
    }

    private function send(string $to, string $message): bool
    {
        try {
            $response = Http::timeout(10)->post("{$this->serviceUrl}/send", [
                'to' => $to,
                'message' => $message,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('WhatsApp send failed', ['to' => $to, 'error' => $response->json('error')]);
            return false;
        } catch (\Exception $e) {
            Log::error('WhatsApp service error', ['to' => $to, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
