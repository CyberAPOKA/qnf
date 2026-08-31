<?php

namespace App\WhatsApp\Commands;

use App\Enums\ParticipationOutcome;
use App\Models\User;
use App\Services\GameParticipationService;
use App\Services\GameService;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\Support\MentionedPlayerResolver;
use App\WhatsApp\WhatsAppCommandMessages;
use Illuminate\Validation\ValidationException;

class AddPlayerCommand implements WhatsAppCommand
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly GameParticipationService $participationService,
        private readonly MentionedPlayerResolver $playerResolver,
    ) {}

    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): string
    {
        if (! $this->playerResolver->hasTargetHint($message, $command)) {
            return WhatsAppCommandMessages::invalidNumber();
        }

        $target = $this->playerResolver->resolve($message, $command);

        if (! $target) {
            return WhatsAppCommandMessages::playerNotFound();
        }

        $adminName = WhatsAppCommandMessages::firstName($sender->name);
        $playerName = WhatsAppCommandMessages::firstName($target->name);

        try {
            $result = $this->participationService->joinOrWaitlist(
                $this->gameService->getOrCreateThisWeekGame(),
                $target,
            );
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first()
                ?: WhatsAppCommandMessages::gameUnavailable();
        }

        $waitlisted = in_array($result->outcome, [
            ParticipationOutcome::Waitlisted,
            ParticipationOutcome::AlreadyWaitlisted,
        ], true);

        if ($result->outcome === ParticipationOutcome::AlreadyJoined) {
            return WhatsAppCommandMessages::alreadyJoined($playerName);
        }

        if ($result->outcome === ParticipationOutcome::AlreadyWaitlisted) {
            return WhatsAppCommandMessages::alreadyWaitlisted($playerName, $result->waitlistPosition);
        }

        return WhatsAppCommandMessages::added(
            $adminName,
            $playerName,
            $waitlisted,
            $result->waitlistPosition,
        );
    }
}
