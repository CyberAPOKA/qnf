<?php

namespace App\WhatsApp;

use App\Support\PhoneNumber;
use App\WhatsApp\Commands\AddPlayerCommand;
use App\WhatsApp\Commands\HelpCommand;
use App\WhatsApp\Commands\LineupCommand;
use App\WhatsApp\Commands\PlayCommand;
use App\WhatsApp\Commands\QuitCommand;
use App\WhatsApp\Commands\RemovePlayerCommand;
use App\WhatsApp\Commands\WhatsAppCommand;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\WhatsAppCommandResult;
use App\WhatsApp\Enums\WhatsAppCommandType;

class WhatsAppCommandProcessor
{
    public function __construct(
        private readonly WhatsAppCommandParser $parser,
        private readonly WhatsAppCommandRateLimiter $rateLimiter,
        private readonly WhatsAppMessageIdempotency $idempotency,
        private readonly PlayCommand $playCommand,
        private readonly QuitCommand $quitCommand,
        private readonly HelpCommand $helpCommand,
        private readonly AddPlayerCommand $addPlayerCommand,
        private readonly RemovePlayerCommand $removePlayerCommand,
        private readonly LineupCommand $lineupCommand,
    ) {}

    /**
     * @return array{status: string, reply: string|null, audio_path: string|null, cleanup_audio: bool}
     */
    public function process(IncomingWhatsAppMessage $message): array
    {
        if ($message->fromMe) {
            return $this->ignored();
        }

        $configuredGroupId = config('services.whatsapp.group_id');

        if (! is_string($configuredGroupId) || $configuredGroupId === '' || $message->chatId !== $configuredGroupId) {
            return $this->ignored();
        }

        $parsed = $this->parser->parse($message);

        if (! $parsed) {
            return $this->ignored();
        }

        if (! $this->idempotency->claim($message->messageId)) {
            return ['status' => 'duplicate', 'reply' => null, 'audio_path' => null, 'cleanup_audio' => false];
        }

        $senderPhone = $message->authorPhone ?? $message->authorId;
        $sender = PhoneNumber::findUser($senderPhone);
        $unlimited = $this->rateLimiter->isUnlimited($senderPhone);
        $isAdmin = $sender?->role === 'admin' || $unlimited;
        $rateLimitPhone = $this->rateLimitPhone($senderPhone, $sender?->id);
        $bypassRateLimit = $unlimited || $parsed->type->isAdmin();

        if (! $sender) {
            if (! in_array($parsed->type, [
                WhatsAppCommandType::Commands,
                WhatsAppCommandType::Lineup,
                WhatsAppCommandType::Add,
                WhatsAppCommandType::Remove,
            ], true)) {
                if ($this->rateLimiter->tooManyAttempts($parsed->type, $rateLimitPhone, $unlimited)) {
                    return $this->rateLimited();
                }

                $this->rateLimiter->hit($parsed->type, $rateLimitPhone, $unlimited);
            }

            return $this->ignored();
        }

        if ($parsed->type->isAdmin() && ! $isAdmin) {
            return $this->ignored();
        }

        if ($this->rateLimiter->tooManyAttempts($parsed->type, $rateLimitPhone, $bypassRateLimit)) {
            return $this->rateLimited();
        }

        $handled = $this->command($parsed->type)->handle($message, $parsed, $sender);

        if ($this->shouldHit($parsed->type, $handled)) {
            $this->rateLimiter->hit($parsed->type, $rateLimitPhone, $bypassRateLimit);
        }

        return $this->fromResult($handled);
    }

    private function command(WhatsAppCommandType $type): WhatsAppCommand
    {
        return match ($type) {
            WhatsAppCommandType::Play => $this->playCommand,
            WhatsAppCommandType::Quit => $this->quitCommand,
            WhatsAppCommandType::Commands => $this->helpCommand,
            WhatsAppCommandType::Add => $this->addPlayerCommand,
            WhatsAppCommandType::Remove => $this->removePlayerCommand,
            WhatsAppCommandType::Lineup => $this->lineupCommand,
        };
    }

    private function shouldHit(WhatsAppCommandType $type, WhatsAppCommandResult $handled): bool
    {
        return match ($type) {
            WhatsAppCommandType::Add, WhatsAppCommandType::Remove => false,
            WhatsAppCommandType::Lineup => $handled->audioPath !== null,
            default => true,
        };
    }

    private function rateLimitPhone(?string $phone, ?int $userId): string
    {
        $lastEight = PhoneNumber::lastEight($phone);

        if ($lastEight) {
            return $lastEight;
        }

        $digits = PhoneNumber::digits($phone);

        if ($digits !== '') {
            return $digits;
        }

        return $userId ? (string) $userId : 'unknown';
    }

    /**
     * @return array{status: string, reply: string|null, audio_path: string|null, cleanup_audio: bool}
     */
    private function ignored(): array
    {
        return ['status' => 'ignored', 'reply' => null, 'audio_path' => null, 'cleanup_audio' => false];
    }

    /**
     * @return array{status: string, reply: string|null, audio_path: string|null, cleanup_audio: bool}
     */
    private function rateLimited(): array
    {
        return ['status' => 'rate_limited', 'reply' => null, 'audio_path' => null, 'cleanup_audio' => false];
    }

    /**
     * @return array{status: string, reply: string|null, audio_path: string|null, cleanup_audio: bool}
     */
    private function fromResult(WhatsAppCommandResult $result): array
    {
        return [
            'status' => 'ok',
            'reply' => null,
            'audio_path' => $result->audioPath,
            'cleanup_audio' => $result->cleanupAudio,
        ];
    }
}
