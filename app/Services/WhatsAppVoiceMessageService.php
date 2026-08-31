<?php

namespace App\Services;

use App\Enums\NarratorVoice;
use App\Services\FishAudio\FishAudioService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $disk = (string) config('fish-audio.disk', 'local');
        $path = 'whatsapp/voice-'.Str::uuid().'.mp3';

        Storage::disk($disk)->put($path, $audio->contents);

        try {
            $sent = $this->whatsApp->sendAudioToGroup(
                Storage::disk($disk)->path($path),
            );

            if (! $sent) {
                throw new RuntimeException('WhatsApp voice message send failed.');
            }
        } finally {
            Storage::disk($disk)->delete($path);
        }
    }
}
