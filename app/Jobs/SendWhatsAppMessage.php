<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;
    public int $timeout = 60;

    public function __construct(
        public string $target,           // 'group' | 'phone'
        public string $kind,             // 'text' | 'image'
        public string $message,
        public ?string $phone = null,
        public ?string $imagePath = null,
    ) {}

    public function handle(WhatsAppService $whatsAppService): void
    {
        if ($this->target === 'group') {
            if ($this->kind === 'image' && $this->imagePath) {
                $whatsAppService->sendImageToGroup($this->imagePath, $this->message);
                return;
            }

            $whatsAppService->sendToGroup($this->message);
            return;
        }

        if ($this->target === 'phone' && $this->phone) {
            $whatsAppService->sendToPhone($this->phone, $this->message);
        }
    }
}
