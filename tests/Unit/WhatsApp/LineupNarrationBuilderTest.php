<?php

namespace Tests\Unit\WhatsApp;

use App\Enums\GameStatus;
use App\Enums\NarratorVoice;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\WhatsApp\Support\LineupNarrationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineupNarrationBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_the_lineup_script_with_goalkeeper_and_lula_suffix(): void
    {
        $game = $this->makeGame();
        $captain = User::factory()->create(['name' => 'Christian', 'position' => Position::FIXED]);
        $team = $this->makeTeam($game, TeamColor::BLUE, $captain);

        $this->addPick($game, $team, User::factory()->create(['name' => 'Daniel', 'position' => Position::WINGER]));
        $this->addPick($game, $team, User::factory()->create(['name' => 'Gustavo Mendes', 'position' => Position::WINGER]));
        $this->addPick($game, $team, User::factory()->create(['name' => 'Rodrigo Lima', 'position' => Position::PIVOT]));
        $this->addPick($game, $team, User::factory()->create(['name' => 'João', 'position' => Position::GOALKEEPER]));

        $text = app(LineupNarrationBuilder::class)->build(
            $game->fresh(['teams.captain', 'draftPicks.pickedUser']),
            $team->fresh('captain'),
            NarratorVoice::LULA,
        );

        $this->assertSame(
            'Escalação do time azul: Christian, Daniel, Gustavo Mendes, Rodrigo Lima e no gol João. Se esse time ganhar eu vou liberar picanha para toda a QNF.',
            $text,
        );
    }

    public function test_it_uses_the_bolsonaro_and_neymar_suffixes(): void
    {
        $game = $this->makeGame();
        $captain = User::factory()->create(['name' => 'Capitão', 'position' => Position::FIXED]);
        $team = $this->makeTeam($game, TeamColor::YELLOW, $captain);
        $this->addPick($game, $team, User::factory()->create(['name' => 'Goleiro', 'position' => Position::GOALKEEPER]));

        $freshGame = $game->fresh(['teams.captain', 'draftPicks.pickedUser']);
        $freshTeam = $team->fresh('captain');

        $bolsonaro = app(LineupNarrationBuilder::class)->build($freshGame, $freshTeam, NarratorVoice::BOLSONARO);
        $neymar = app(LineupNarrationBuilder::class)->build($freshGame, $freshTeam, NarratorVoice::NEYMAR);

        $this->assertStringContainsString('Escalação do time amarelo:', $bolsonaro);
        $this->assertStringContainsString('Brasil acima de tudo, Deus acima de todos, ihuuuuu hahahaha ta ok!', $bolsonaro);
        $this->assertStringContainsString('Fiquei muito triste que meu menino Salenave não vai jogar hoje, que decepção!', $neymar);
    }

    public function test_it_returns_null_when_the_team_has_no_players(): void
    {
        $game = $this->makeGame();
        $team = Team::create([
            'game_id' => $game->id,
            'color' => TeamColor::GREEN,
            'captain_user_id' => null,
            'pick_order' => 1,
        ]);

        $text = app(LineupNarrationBuilder::class)->build(
            $game->fresh(['teams.captain', 'draftPicks.pickedUser']),
            $team,
            NarratorVoice::LULA,
        );

        $this->assertNull($text);
    }

    private function makeGame(): Game
    {
        return Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::DRAFTED,
            'round' => 1,
        ]);
    }

    private function makeTeam(Game $game, TeamColor $color, User $captain): Team
    {
        return Team::create([
            'game_id' => $game->id,
            'color' => $color,
            'captain_user_id' => $captain->id,
            'pick_order' => $game->teams()->count() + 1,
        ]);
    }

    private function addPick(Game $game, Team $team, User $user): void
    {
        $count = $game->draftPicks()->count();

        DraftPick::create([
            'game_id' => $game->id,
            'round' => intdiv($count, 3) + 1,
            'pick_in_round' => ($count % 3) + 1,
            'team_color' => $team->color,
            'picked_user_id' => $user->id,
            'picked_at' => now(),
        ]);
    }
}
