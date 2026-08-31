<?php

namespace App\WhatsApp;

use App\Support\PhoneNumber;
use App\WhatsApp\Commands\AddPlayerCommand;
use App\WhatsApp\Commands\HelpCommand;
use App\WhatsApp\Commands\PlayCommand;
use App\WhatsApp\Commands\QuitCommand;
use App\WhatsApp\Commands\RemovePlayerCommand;
use App\WhatsApp\Commands\WhatsAppCommand;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
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
    ) {}

    /**
     * @return array{status: string, reply: string|null}
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
            return ['status' => 'duplicate', 'reply' => null];
        }

        $senderPhone = $message->authorPhone ?? $message->authorId;
        $sender = PhoneNumber::findUser($senderPhone);
        $isAdmin = $sender?->role === 'admin';
        $rateLimitPhone = $this->rateLimitPhone($senderPhone, $sender?->id);

        if (! $sender) {
            if ($parsed->type !== WhatsAppCommandType::Commands) {
                if ($this->rateLimiter->tooManyAttempts($parsed->type, $rateLimitPhone, false)) {
                    return $this->replied(WhatsAppCommandMessages::rateLimited($parsed->type));
                }

                $this->rateLimiter->hit($parsed->type, $rateLimitPhone, false);
            }

            return $this->replied(WhatsAppCommandMessages::unknownPlayer());
        }

        if ($parsed->type->isAdmin() && ! $isAdmin) {
            return $this->replied(WhatsAppCommandMessages::unauthorized());
        }

        if ($this->rateLimiter->tooManyAttempts($parsed->type, $rateLimitPhone, $isAdmin)) {
            return $this->replied(WhatsAppCommandMessages::rateLimited($parsed->type));
        }

        $reply = $this->command($parsed->type)->handle($message, $parsed, $sender);

        $this->rateLimiter->hit($parsed->type, $rateLimitPhone, $isAdmin);

        return $this->replied($reply);
    }

    private function command(WhatsAppCommandType $type): WhatsAppCommand
    {
        return match ($type) {
            WhatsAppCommandType::Play => $this->playCommand,
            WhatsAppCommandType::Quit => $this->quitCommand,
            WhatsAppCommandType::Commands => $this->helpCommand,
            WhatsAppCommandType::Add => $this->addPlayerCommand,
            WhatsAppCommandType::Remove => $this->removePlayerCommand,
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
     * @return array{status: string, reply: string|null}
     */
    private function ignored(): array
    {
        return ['status' => 'ignored', 'reply' => null];
    }

    /**
     * @return array{status: string, reply: string}
     */
    private function replied(string $reply): array
    {
        return ['status' => 'ok', 'reply' => $reply];
    }
}
