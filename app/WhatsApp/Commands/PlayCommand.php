<?php

namespace App\WhatsApp\Commands;

use App\Enums\ParticipationOutcome;
use App\Models\User;
use App\Services\GameParticipationService;
use App\Services\GameService;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\WhatsAppCommandMessages;
use Illuminate\Validation\ValidationException;

class PlayCommand implements WhatsAppCommand
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly GameParticipationService $participationService,
    ) {}

    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): string
    {
        $name = WhatsAppCommandMessages::firstName($sender->name);

        try {
            $result = $this->participationService->joinOrWaitlist(
                $this->gameService->getOrCreateThisWeekGame(),
                $sender,
            );
        } catch (ValidationException $exception) {
            return $this->firstError($exception) ?? WhatsAppCommandMessages::gameUnavailable();
        }

        return match ($result->outcome) {
            ParticipationOutcome::Joined => WhatsAppCommandMessages::joined($name),
            ParticipationOutcome::Waitlisted => WhatsAppCommandMessages::waitlisted($name, $result->waitlistPosition ?? 1),
            ParticipationOutcome::AlreadyJoined => WhatsAppCommandMessages::alreadyJoined($name),
            ParticipationOutcome::AlreadyWaitlisted => WhatsAppCommandMessages::alreadyWaitlisted($name, $result->waitlistPosition),
            default => WhatsAppCommandMessages::gameUnavailable(),
        };
    }

    private function firstError(ValidationException $exception): ?string
    {
        return collect($exception->errors())->flatten()->first();
    }
}
