<?php

namespace App\Services;

use App\Enums\NarratorVoice;
use App\Services\FishAudio\FishAudioService;
use App\WhatsApp\Support\WhatsAppAudioTempFile;
use RuntimeException;

class WhatsAppVoiceMessageService
{
    public function __construct(
        private readonly FishAudioService $fishAudio,
        private readonly WhatsAppService $whatsApp,
    ) {}

    public function sendToGroup(string $text, NarratorVoice $voice): void
    {
        $audio = $this->fishAudio->generate($text, $voice->value);
        $path = WhatsAppAudioTempFile::put($audio->contents);

        try {
            $sent = $this->whatsApp->sendAudioToGroup($path);

            if (! $sent) {
                throw new RuntimeException('WhatsApp voice message send failed.');
            }
        } finally {
            @unlink($path);
        }
    }
}
