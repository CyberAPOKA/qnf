<?php

namespace App\WhatsApp\Commands;

use App\Enums\ParticipationOutcome;
use App\Models\User;
use App\Services\GameParticipationService;
use App\Services\GameService;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\Data\WhatsAppCommandResult;
use App\WhatsApp\WhatsAppCommandMessages;
use Illuminate\Validation\ValidationException;

class QuitCommand implements WhatsAppCommand
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly GameParticipationService $participationService,
    ) {}

    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): WhatsAppCommandResult
    {
        $name = WhatsAppCommandMessages::firstName($sender->name);

        try {
            $result = $this->participationService->quit(
                $this->gameService->getOrCreateThisWeekGame(),
                $sender,
            );
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->first();

            return WhatsAppCommandResult::text($error ?: WhatsAppCommandMessages::notParticipating($name));
        }

        if ($result->outcome === ParticipationOutcome::LeftWaitlist) {
            return WhatsAppCommandResult::text(WhatsAppCommandMessages::leftWaitlist($name));
        }

        $promotedName = $result->promoted
            ? WhatsAppCommandMessages::firstName($result->promoted->name)
            : null;

        return WhatsAppCommandResult::text(WhatsAppCommandMessages::quit($name, $promotedName));
    }
}
