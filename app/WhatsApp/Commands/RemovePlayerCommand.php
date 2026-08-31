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

class RemovePlayerCommand implements WhatsAppCommand
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
            $result = $this->participationService->removePlayer(
                $this->gameService->getOrCreateThisWeekGame(),
                $target,
            );
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first()
                ?: WhatsAppCommandMessages::playerNotFound();
        }

        $promotedName = $result->promoted
            ? WhatsAppCommandMessages::firstName($result->promoted->name)
            : null;

        return WhatsAppCommandMessages::removed(
            $adminName,
            $playerName,
            $result->outcome === ParticipationOutcome::RemovedFromWaitlist,
            $promotedName,
        );
    }
}
