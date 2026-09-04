<?php

namespace App\WhatsApp\Commands;

use App\Exceptions\FishAudio\FishAudioException;
use App\Models\Team;
use App\Models\User;
use App\Services\FishAudio\FishAudioService;
use App\Services\GameService;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Data\ParsedWhatsAppCommand;
use App\WhatsApp\Data\WhatsAppCommandResult;
use App\WhatsApp\Support\LineupArguments;
use App\WhatsApp\Support\LineupNarrationBuilder;
use App\WhatsApp\Support\WhatsAppAudioTempFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

class LineupCommand implements WhatsAppCommand
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly FishAudioService $fishAudio,
        private readonly LineupNarrationBuilder $narrationBuilder,
    ) {}

    public function handle(IncomingWhatsAppMessage $message, ParsedWhatsAppCommand $command, User $sender): WhatsAppCommandResult
    {
        set_time_limit(120);

        $arguments = LineupArguments::parse($command->argument);

        if (! $arguments) {
            return WhatsAppCommandResult::silent();
        }

        if (! config('fish-audio.enabled')) {
            return WhatsAppCommandResult::silent();
        }

        try {
            $game = $this->gameService->getOrCreateThisWeekGame();
        } catch (ModelNotFoundException) {
            return WhatsAppCommandResult::silent();
        }

        $game->loadMissing(['teams.captain', 'draftPicks.pickedUser']);

        $team = $game->teams->first(
            fn (Team $team) => $team->color === $arguments->color
        );

        if (! $team) {
            return WhatsAppCommandResult::silent();
        }

        $script = $this->narrationBuilder->build($game, $team, $arguments->voice);

        if ($script === null) {
            return WhatsAppCommandResult::silent();
        }

        try {
            $audio = $this->fishAudio->generate($script, $arguments->voice->value);
        } catch (FishAudioException $exception) {
            Log::error('[WhatsApp] lineup audio generation failed', [
                'voice' => $arguments->voice->value,
                'color' => $arguments->color->value,
                'error' => $exception->getMessage(),
            ]);

            return WhatsAppCommandResult::silent();
        } catch (Throwable $exception) {
            Log::error('[WhatsApp] lineup unexpected error', [
                'voice' => $arguments->voice->value,
                'color' => $arguments->color->value,
                'error' => $exception->getMessage(),
            ]);

            return WhatsAppCommandResult::silent();
        }

        return WhatsAppCommandResult::audio(WhatsAppAudioTempFile::put($audio->contents, 'lineup'));
    }
}
