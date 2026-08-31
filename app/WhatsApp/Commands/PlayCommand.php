<?php

namespace App\WhatsApp\Commands;

use App\Models\User;
use App\Services\GameParticipationService;
use App\Services\GameService;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\Data\WhatsAppCommandResult;
use Illuminate\Validation\ValidationException;

class PlayCommand implements WhatsAppCommand
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly GameParticipationService $participationService,
    ) {}

    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): WhatsAppCommandResult
    {
        try {
            $this->participationService->joinOrWaitlist(
                $this->gameService->getOrCreateThisWeekGame(),
                $sender,
            );
        } catch (ValidationException) {
            return WhatsAppCommandResult::silent();
        }

        return WhatsAppCommandResult::silent();
    }
}
