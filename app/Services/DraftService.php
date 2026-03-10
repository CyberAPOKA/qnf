<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Events\DraftFinished;
use App\Events\DraftPickMade;
use App\Events\DraftTurnChanged;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\Support\GamePayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DraftService
{
    public function __construct(
        private readonly ScoringService $scoringService,
        private readonly WhatsAppService $whatsAppService,
    ) {}

    public const SNAKE_SEQUENCE = [
        TeamColor::GREEN, TeamColor::YELLOW, TeamColor::BLUE,
        TeamColor::BLUE, TeamColor::YELLOW, TeamColor::GREEN,
        TeamColor::GREEN, TeamColor::YELLOW, TeamColor::BLUE,
        TeamColor::BLUE, TeamColor::YELLOW, TeamColor::GREEN,
    ];

    public function drawCaptains(Game $game): array
    {
        if ($game->status !== GameStatus::FULL) {
            throw ValidationException::withMessages([
                'game' => 'O jogo precisa estar lotado para sortear capitães.',
            ]);
        }

        $goalkeeperCount = $game->players()->where('position', Position::GOALKEEPER)->count();
        if ($goalkeeperCount < 3) {
            throw ValidationException::withMessages([
                'captains' => 'São necessários pelo menos 3 goleiros para iniciar o draft.',
            ]);
        }

        $previousGame = Game::where('id', '<', $game->id)->orderByDesc('id')->first();

        $previousCaptainIds = $previousGame
            ? Team::where('game_id', $previousGame->id)->whereNotNull('captain_user_id')->pluck('captain_user_id')->values()
            : collect();

        $query = $game->players()
            ->where('users.position', '!=', Position::GOALKEEPER)
            ->where('users.guest', false);

        if ($previousCaptainIds->isNotEmpty()) {
            $query->whereNotIn('users.id', $previousCaptainIds);
        }

        $candidates = $query->inRandomOrder()->take(3)->get();

        if ($candidates->count() < 3) {
            throw ValidationException::withMessages([
                'captains' => 'Não há jogadores elegíveis suficientes sem repetir capitães do jogo anterior.',
            ]);
        }

        $colors = TeamColor::cases();

        DB::transaction(function () use ($game, $candidates, $colors): void {
            foreach ($colors as $index => $color) {
                Team::updateOrCreate(
                    ['game_id' => $game->id, 'color' => $color],
                    [
                        'captain_user_id' => $candidates[$index]->id,
                        'pick_order' => $index + 1,
                    ]
                );
            }

            $game->update(['status' => GameStatus::DRAFTING]);
        });

        $this->notifyWhatsapp($game, $candidates, $colors);

        return $candidates->values()->all();
    }

    private function notifyWhatsapp(Game $game, $candidates, array $colors): void
    {
        $colorEmojis = [
            TeamColor::GREEN->value => '🟢',
            TeamColor::YELLOW->value => '🟡',
            TeamColor::BLUE->value => '🔵',
        ];

        $round = $game->round ?? 0;

        // Mensagem para o grupo
        $lines = ["*⚽️ Rodada {$round} — Capitães Sorteados!*", ''];
        foreach ($colors as $index => $color) {
            $emoji = $colorEmojis[$color->value];
            $lines[] = "{$emoji} {$candidates[$index]->name}";
        }
        $lines[] = '';
        $lines[] = 'Capitães, acessem o app para realizarem o draft! 🏆';
        $groupMessage = implode("\n", $lines);
        rescue(fn () => $this->whatsAppService->sendToGroup($groupMessage), report: false);

        // Mensagem pessoal para cada capitão
        // foreach ($colors as $index => $color) {
        //     $captain = $candidates[$index];
        //     $emoji = $colorEmojis[$color->value];
        //     $label = $color->label();

        //     if ($captain->phone && $captain->whatsapp_notifications) {
        //         $personalMessage = "Fala, {$captain->name}! ⚽️\n\nVocê foi sorteado como capitão do time {$emoji} *{$label}* na rodada {$round}.\n\nAcesse o app para realizar suas escolhas no draft! 🏆";
        //         rescue(fn () => $this->whatsAppService->sendToPhone($captain->phone, $personalMessage), report: false);
        //     }
        // }
    }

    public function currentTurnColor(Game $game): ?TeamColor
    {
        $totalPicks = $game->draftPicks()->count();

        if ($totalPicks >= 12) {
            return null;
        }

        return self::SNAKE_SEQUENCE[$totalPicks];
    }

    public function canPick(User $actor, Game $game): bool
    {
        if ($game->status !== GameStatus::DRAFTING) {
            return false;
        }

        $turnColor = $this->currentTurnColor($game);
        if (! $turnColor) {
            return false;
        }

        $team = $game->teams()->where('color', $turnColor)->first();

        return $team && $team->captain_user_id === $actor->id;
    }

    public function makePick(Game $game, int $pickedUserId, int $actorUserId): DraftPick
    {
        return DB::transaction(function () use ($game, $pickedUserId, $actorUserId): DraftPick {
            $lockedGame = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actorUserId);

            if ($lockedGame->status !== GameStatus::DRAFTING) {
                throw ValidationException::withMessages(['game' => 'Draft não está ativo.']);
            }

            $turnColor = $this->currentTurnColor($lockedGame);
            if (! $turnColor) {
                throw ValidationException::withMessages(['game' => 'Draft já foi finalizado.']);
            }

            $turnTeam = $lockedGame->teams()->where('color', $turnColor)->first();
            if (! $turnTeam) {
                throw ValidationException::withMessages(['draft' => 'Time do turno atual não foi definido.']);
            }

            $isCaptainTurn = $turnTeam->captain_user_id === $actor->id;
            if (! $isCaptainTurn) {
                throw new AuthorizationException('Você não pode escolher neste turno.');
            }

            $isInGame = $lockedGame->players()->where('users.id', $pickedUserId)->exists();
            if (! $isInGame) {
                throw ValidationException::withMessages(['user_id' => 'Jogador não está inscrito nesta partida.']);
            }

            $isCaptain = $lockedGame->teams()->where('captain_user_id', $pickedUserId)->exists();
            if ($isCaptain) {
                throw ValidationException::withMessages(['user_id' => 'Não é permitido escolher capitães.']);
            }

            $alreadyPicked = $lockedGame->draftPicks()->where('picked_user_id', $pickedUserId)->exists();
            if ($alreadyPicked) {
                throw ValidationException::withMessages(['user_id' => 'Jogador já foi escolhido.']);
            }

            $pickedUser = User::findOrFail($pickedUserId);

            $teamGoalkeeperCount = $lockedGame->draftPicks()
                ->where('team_color', $turnColor)
                ->whereHas('pickedUser', fn ($q) => $q->where('position', Position::GOALKEEPER))
                ->count();

            $teamLinePickCount = $lockedGame->draftPicks()
                ->where('team_color', $turnColor)
                ->whereHas('pickedUser', fn ($q) => $q->where('position', '!=', Position::GOALKEEPER))
                ->count();

            if ($pickedUser->position === Position::GOALKEEPER && $teamGoalkeeperCount >= 1) {
                throw ValidationException::withMessages(['user_id' => 'Cada time pode ter no máximo 1 goleiro.']);
            }

            if ($pickedUser->position !== Position::GOALKEEPER && $teamLinePickCount >= 3) {
                throw ValidationException::withMessages(['user_id' => 'O time já tem 3 jogadores de linha. Escolha um goleiro.']);
            }

            $pickIndex = $lockedGame->draftPicks()->count();
            $pick = DraftPick::create([
                'game_id' => $lockedGame->id,
                'round' => intdiv($pickIndex, 3) + 1,
                'pick_in_round' => ($pickIndex % 3) + 1,
                'team_color' => $turnColor,
                'picked_user_id' => $pickedUserId,
                'picked_at' => now(),
            ]);

            $teamPickCount = $lockedGame->draftPicks()
                ->where('team_color', $turnColor)
                ->where('id', '!=', $pick->id)
                ->count();

            if ($teamPickCount === 0) {
                $turnTeam->update(['first_pick_user_id' => $pickedUserId]);
            }

            $totalAfter = $lockedGame->draftPicks()->count();

            // Auto-assign last remaining player when only 1 is left
            if ($totalAfter === 11) {
                $captainIds = $lockedGame->teams()->whereNotNull('captain_user_id')->pluck('captain_user_id');
                $pickedIds = $lockedGame->draftPicks()->pluck('picked_user_id');

                $lastPlayer = $lockedGame->players()
                    ->whereNotIn('users.id', $captainIds)
                    ->whereNotIn('users.id', $pickedIds)
                    ->first();

                if ($lastPlayer) {
                    $lastTurnColor = self::SNAKE_SEQUENCE[11];
                    $lastTeam = $lockedGame->teams()->where('color', $lastTurnColor)->first();

                    $autoPick = DraftPick::create([
                        'game_id' => $lockedGame->id,
                        'round' => intdiv(11, 3) + 1,
                        'pick_in_round' => (11 % 3) + 1,
                        'team_color' => $lastTurnColor,
                        'picked_user_id' => $lastPlayer->id,
                        'picked_at' => now(),
                    ]);

                    $lastTeamPickCount = $lockedGame->draftPicks()
                        ->where('team_color', $lastTurnColor)
                        ->where('id', '!=', $autoPick->id)
                        ->count();

                    if ($lastTeamPickCount === 0 && $lastTeam) {
                        $lastTeam->update(['first_pick_user_id' => $lastPlayer->id]);
                    }

                    $totalAfter = 12;
                }
            }

            if ($totalAfter >= 12) {
                $lockedGame->update(['status' => GameStatus::DRAFTED]);
            }

            $lockedGame->refresh();
            $lockedGame->loadMissing(['teams.captain', 'draftPicks.pickedUser', 'players']);
            $payload = GamePayload::fromGame($lockedGame, $this, $this->scoringService);

            rescue(fn () => broadcast(new DraftPickMade($payload))->toOthers(), report: false);
            rescue(fn () => broadcast(new DraftTurnChanged($payload))->toOthers(), report: false);

            if ($lockedGame->status === GameStatus::DRAFTED) {
                $whatsappMessage = $this->buildWhatsAppMessage($lockedGame);

                rescue(
                    fn () => broadcast(new DraftFinished($payload, $whatsappMessage))->toOthers(),
                    report: false
                );

                rescue(fn () => $this->whatsAppService->sendToGroup($whatsappMessage), report: false);
            }

            return $pick;
        });
    }

    public function buildWhatsAppMessage(Game $game): string
    {
        $game->loadMissing(['teams.captain', 'teams.firstPick', 'draftPicks.pickedUser']);

        $colorEmojis = [
            TeamColor::GREEN->value => '🟢',
            TeamColor::YELLOW->value => '🟡',
            TeamColor::BLUE->value => '🔵',
        ];

        $teamsByColor = $game->teams->keyBy(fn ($team) => $team->color->value);
        $lines = ["*📋 TIMES*", '', '•••••••••••••••••••••••••••••••••••••', ''];

        foreach (TeamColor::cases() as $color) {
            $emoji = $colorEmojis[$color->value];
            $team = $teamsByColor->get($color->value);
            $captainName = $team?->captain?->name;
            $firstPickId = $team?->first_pick_user_id;

            if ($captainName) {
                $lines[] = "{$emoji} {$captainName}©️";
            }

            foreach ($game->draftPicks->where('team_color', $color)->sortBy('id') as $pick) {
                $name = $pick->pickedUser->name;
                $badge = '';

                if ($pick->pickedUser->position === Position::GOALKEEPER) {
                    $badge = '🧤';
                } elseif ($pick->picked_user_id === $firstPickId) {
                    $badge = '🔟';
                }

                $lines[] = "{$emoji} {$name}{$badge}";
            }

            $lines[] = '';
        }

        $lines[] = '©️ = capitão';
        $lines[] = '🧤 = goleiro';
        $lines[] = '🔟 = 1º escolha';
        $lines[] = '';
        $lines[] = '•••••••••••••••••••••••••••••••••••••';
        $lines[] = '';

        $lines[] = sprintf('*⚽️ Rodada: %02d*', $game->round ?? 0);

        return implode("\n", $lines);
    }

    public function teamsWithPlayers(Game $game): array
    {
        $game->loadMissing(['teams.captain', 'teams.firstPick', 'draftPicks.pickedUser']);

        $result = [];
        $teamsByColor = $game->teams->keyBy(fn ($team) => $team->color->value);

        foreach (TeamColor::cases() as $color) {
            $team = $teamsByColor->get($color->value);
            $captain = $team?->captain;
            $firstPickId = $team?->first_pick_user_id;

            $players = $game->draftPicks
                ->where('team_color', $color)
                ->sortBy('id')
                ->values()
                ->map(fn ($pick) => [
                    'id' => $pick->pickedUser->id,
                    'name' => $pick->pickedUser->name,
                    'position' => $pick->pickedUser->position->value,
                    'position_label' => $pick->pickedUser->position->label(),
                    'is_first_pick' => $pick->picked_user_id === $firstPickId,
                ])
                ->all();

            $result[$color->value] = [
                'captain' => $captain ? [
                    'id' => $captain->id,
                    'name' => $captain->name,
                    'position' => $captain->position->value,
                    'position_label' => $captain->position->label(),
                ] : null,
                'players' => $players,
                'score' => $team?->score,
            ];
        }

        return $result;
    }
}
