<?php

namespace App\WhatsApp\Commands;

use App\Models\User;
use App\Services\GameParticipationService;
use App\Services\GameService;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\Data\WhatsAppCommandResult;
use App\WhatsApp\Support\MentionedPlayerResolver;
use Illuminate\Validation\ValidationException;

class AddPlayerCommand implements WhatsAppCommand
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly GameParticipationService $participationService,
        private readonly MentionedPlayerResolver $playerResolver,
    ) {}

    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): WhatsAppCommandResult
    {
        if (! $this->playerResolver->hasTargetHint($message, $command)) {
            return WhatsAppCommandResult::silent();
        }

        $target = $this->playerResolver->resolve($message, $command);

        if (! $target) {
            return WhatsAppCommandResult::silent();
        }

        try {
            $this->participationService->joinOrWaitlist(
                $this->gameService->getOrCreateThisWeekGame(),
                $target,
            );
        } catch (ValidationException) {
            return WhatsAppCommandResult::silent();
        }

        return WhatsAppCommandResult::silent();
    }
}
