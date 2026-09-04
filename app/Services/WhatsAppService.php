<?php

namespace App\Services;

use App\WhatsApp\Support\WhatsAppAudioTempFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private bool $active;

    private bool $playerActive;

    private string $serviceUrl;

    private ?string $groupId;

    public function __construct()
    {
        $this->active = (bool) config('services.whatsapp.active', false);
        $this->playerActive = (bool) config('services.whatsapp.player_active', false);
        $this->serviceUrl = config('services.whatsapp.url', 'http://127.0.0.1:3001');
        $this->groupId = config('services.whatsapp.group_id');
    }

    public function sendToGroup(string $message): bool
    {
        if (! $this->active) {
            Log::info('[WhatsApp] (inactive) Group message:', ['message' => $message]);

            // No-op success so queued jobs do not retry when WhatsApp is disabled.
            return true;
        }

        if (! $this->groupId) {
            Log::warning('WhatsApp group ID not configured.');

            return false;
        }

        return $this->send($this->groupId, $message);
    }

    public function sendImageToGroup(string $imagePath, string $caption = ''): bool
    {
        if (! $this->active) {
            Log::info('[WhatsApp] (inactive) Group image:', ['image' => $imagePath, 'caption' => $caption]);

            return true;
        }

        if (! $this->groupId) {
            Log::warning('WhatsApp group ID not configured.');

            return false;
        }

        return $this->sendImage($this->groupId, $imagePath, $caption);
    }

    public function sendAudioToGroup(string $audioPath, string $caption = ''): bool
    {
        if (! $this->active) {
            Log::info('[WhatsApp] (inactive) Group audio:', ['audio' => $audioPath, 'caption' => $caption]);

            return true;
        }

        if (! $this->groupId) {
            Log::warning('WhatsApp group ID not configured.');

            return false;
        }

        return $this->sendAudio($this->groupId, $audioPath, $caption);
    }

    public function sendToPhone(string $phone, string $message): bool
    {
        if (! $this->active || ! $this->playerActive) {
            return true;
        }

        $chatId = preg_replace('/\D/', '', $phone).'@c.us';

        return $this->send($chatId, $message);
    }

    private function sendImage(string $to, string $imagePath, string $caption): bool
    {
        try {
            // Image uploads via Puppeteer can take longer, especially after retries.
            $response = Http::timeout(90)->post("{$this->serviceUrl}/send-image", [
                'to' => $to,
                'imagePath' => $imagePath,
                'caption' => $caption,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('WhatsApp send image failed', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
                'imagePath' => $imagePath,
                'imageExists' => file_exists($imagePath),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('WhatsApp image service error', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function sendAudio(string $to, string $audioPath, string $caption): bool
    {
        $stagedPath = null;

        try {
            // Node often runs as another user and cannot read storage/app/private.
            // Stage a 0644 copy under storage/app/tmp (same place /lineup uses) and
            // also send the bytes so a restarted Node does not need the file at all.
            $readablePath = WhatsAppAudioTempFile::copyFrom($audioPath);
            $stagedPath = $readablePath;

            $payload = [
                'to' => $to,
                'audioPath' => $readablePath,
                'caption' => $caption,
                'audioBase64' => base64_encode((string) file_get_contents($readablePath)),
                'audioFilename' => basename($audioPath),
            ];

            $response = Http::timeout(90)->post("{$this->serviceUrl}/send-audio", $payload);

            if ($response->successful()) {
                return true;
            }

            Log::error('WhatsApp send audio failed', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
                'audioPath' => $audioPath,
                'stagedPath' => $readablePath,
                'audioExists' => file_exists($audioPath),
                'stagedExists' => file_exists($readablePath),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('WhatsApp audio service error', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        } finally {
            if ($stagedPath) {
                @unlink($stagedPath);
            }
        }
    }

    private function send(string $to, string $message): bool
    {
        try {
            $response = Http::timeout(30)->post("{$this->serviceUrl}/send", [
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
