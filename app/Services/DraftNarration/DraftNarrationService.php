<?php

namespace App\Services\DraftNarration;

use App\Enums\DraftNarrationStatus;
use App\Enums\GameStatus;
use App\Enums\NarratorVoice;
use App\Enums\TeamColor;
use App\Exceptions\FishAudio\FishAudioException;
use App\Models\DraftNarration;
use App\Models\Game;
use App\Models\Team;
use App\Services\FishAudio\FishAudioService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DraftNarrationService
{
    public function __construct(
        private readonly FishAudioService $fishAudio,
        private readonly DraftNarrationBuilder $builder,
        private readonly DraftNarrationStorage $storage,
        private readonly WhatsAppService $whatsApp,
    ) {}

    public function processGame(Game $game): void
    {
        if (! config('fish-audio.enabled')) {
            return;
        }

        if (! in_array($game->status, [GameStatus::DRAFTED, GameStatus::DONE], true)) {
            Log::info('Draft narrations skipped: game is not finalized', [
                'game_id' => $game->id,
                'status' => $game->status?->value,
            ]);

            return;
        }

        $game->loadMissing(['teams.captain', 'draftPicks.pickedUser']);

        $voice = NarratorVoice::fromConfig();
        $teams = $this->teamsInDraftOrder($game);

        foreach ($teams as $team) {
            try {
                $this->generateTeamAudio($game, $team, $voice);
            } catch (FishAudioException $e) {
                Log::error('Draft narration generation failed', [
                    'game_id' => $game->id,
                    'team_id' => $team->id,
                    'voice' => $voice->value,
                    'status' => $e->status,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($teams as $team) {
            $narration = $this->narrationFor($game, $team, $voice);

            if (! $narration || $narration->wasSent() || ! $this->storage->exists($narration->path)) {
                continue;
            }

            $this->sendToWhatsApp($game, $team, $narration);
        }
    }

    /**
     * @return list<Team>
     */
    private function teamsInDraftOrder(Game $game): array
    {
        $teamsByColor = $game->teams->keyBy(fn (Team $team) => $team->color->value);

        $teams = [];

        foreach (TeamColor::cases() as $color) {
            $team = $teamsByColor->get($color->value);

            if ($team) {
                $teams[] = $team;
            }
        }

        return $teams;
    }

    private function generateTeamAudio(Game $game, Team $team, NarratorVoice $voice): void
    {
        $narration = $this->narrationFor($game, $team, $voice, create: true);

        if ($narration->wasSent() || $this->storage->exists($narration->path)) {
            return;
        }

        $this->generateAndStore($game, $team, $voice, $narration);
    }

    private function narrationFor(Game $game, Team $team, NarratorVoice $voice, bool $create = false): ?DraftNarration
    {
        $attributes = [
            'game_id' => $game->id,
            'team_id' => $team->id,
            'version' => 1,
        ];

        if ($create) {
            return DraftNarration::query()->firstOrCreate($attributes, [
                'voice' => $voice,
                'status' => DraftNarrationStatus::PENDING,
            ]);
        }

        return DraftNarration::query()->where($attributes)->first();
    }

    private function generateAndStore(Game $game, Team $team, NarratorVoice $voice, DraftNarration $narration): void
    {
        $text = $this->builder->build($game, $team, $voice);

        $narration->update([
            'status' => DraftNarrationStatus::GENERATING,
            'voice' => $voice,
            'text' => $text,
            'error' => null,
        ]);

        Log::info('Generating draft narration', [
            'game_id' => $game->id,
            'team_id' => $team->id,
            'voice' => $voice->value,
            'text_length' => mb_strlen($text),
        ]);

        try {
            $audio = $this->fishAudio->generate($text, $voice->value);
            $path = $this->storage->put($game->id, $team->id, $audio->contents);

            $narration->update([
                'path' => $path,
                'status' => DraftNarrationStatus::GENERATED,
                'error' => null,
            ]);

            Log::info('Draft narration stored', [
                'game_id' => $game->id,
                'team_id' => $team->id,
                'voice' => $voice->value,
                'path' => $path,
                'audio_size' => $audio->size(),
            ]);
        } catch (FishAudioException $e) {
            $narration->update([
                'status' => DraftNarrationStatus::FAILED,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function sendToWhatsApp(Game $game, Team $team, DraftNarration $narration): void
    {
        if (! $this->storage->exists($narration->path)) {
            throw new FishAudioException('Draft narration audio is missing after generation.');
        }

        $absolutePath = $this->storage->absolutePath((string) $narration->path);
        $caption = 'Time '.$team->color->label();

        $narration->update([
            'status' => DraftNarrationStatus::SENDING,
            'error' => null,
        ]);

        $sent = $this->whatsApp->sendAudioToGroup($absolutePath, $caption);

        if (! $sent) {
            $narration->update([
                'status' => DraftNarrationStatus::GENERATED,
                'error' => 'WhatsApp send failed',
            ]);

            throw new RuntimeException('WhatsApp draft narration send failed.');
        }

        $narration->update([
            'status' => DraftNarrationStatus::SENT,
            'whatsapp_sent_at' => now(),
            'error' => null,
        ]);

        Log::info('Draft narration sent to WhatsApp', [
            'game_id' => $game->id,
            'team_id' => $team->id,
            'voice' => $narration->voice?->value,
            'whatsapp_status' => 'sent',
        ]);
    }
}
