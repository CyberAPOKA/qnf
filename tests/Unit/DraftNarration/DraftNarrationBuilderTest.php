<?php

namespace Tests\Unit\DraftNarration;

use App\Enums\GameStatus;
use App\Enums\NarratorVoice;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftNarration\DraftNarrationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftNarrationBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_natural_portuguese_text_with_positions_and_accents(): void
    {
        $game = $this->makeGame();
        $captain = User::factory()->create(['name' => 'Salenave', 'position' => Position::FIXED]);
        $goalkeeper = User::factory()->create(['name' => 'José', 'position' => Position::GOALKEEPER]);
        $leftWinger = User::factory()->create(['name' => 'Guto Cenci', 'position' => Position::WINGER]);
        $rightWinger = User::factory()->create(['name' => 'Brayan', 'position' => Position::WINGER]);
        $pivot = User::factory()->create(['name' => 'Costinha', 'position' => Position::PIVOT]);

        $team = $this->makeTeam($game, TeamColor::YELLOW, $captain);
        $this->addPick($game, $team, $goalkeeper);
        $this->addPick($game, $team, $leftWinger);
        $this->addPick($game, $team, $rightWinger);
        $this->addPick($game, $team, $pivot);

        $text = app(DraftNarrationBuilder::class)->build($game->fresh(['teams.captain', 'draftPicks.pickedUser']), $team->fresh('captain'), NarratorVoice::LULA);

        $this->assertStringContainsString('Convocação do time amarelo.', $text);
        $this->assertStringContainsString('Goleiro: José.', $text);
        $this->assertStringContainsString('Fixo: Salenave.', $text);
        $this->assertStringContainsString('Ala esquerdo: Guto Cenci.', $text);
        $this->assertStringContainsString('Ala direito: Brayan.', $text);
        $this->assertStringContainsString('Pivô: Costinha.', $text);
        $this->assertStringContainsString('Se esse time ganhar, eu vou liberar picanha de graça para toda a QNF.', $text);
        $this->assertStringNotContainsString('*', $text);
        $this->assertStringNotContainsString('#', $text);
    }

    public function test_it_skips_missing_positions(): void
    {
        $game = $this->makeGame();
        $captain = User::factory()->create(['name' => 'Salenave', 'position' => Position::FIXED]);
        $goalkeeper = User::factory()->create(['name' => 'Goleiro Um', 'position' => Position::GOALKEEPER]);

        $team = $this->makeTeam($game, TeamColor::GREEN, $captain);
        $this->addPick($game, $team, $goalkeeper);

        $text = app(DraftNarrationBuilder::class)->build($game->fresh(['teams.captain', 'draftPicks.pickedUser']), $team->fresh('captain'), NarratorVoice::LULA);

        $this->assertStringContainsString('Convocação do time verde.', $text);
        $this->assertStringContainsString('Goleiro: Goleiro Um.', $text);
        $this->assertStringContainsString('Fixo: Salenave.', $text);
        $this->assertStringNotContainsString('Ala', $text);
        $this->assertStringNotContainsString('Pivô', $text);
    }

    public function test_it_uses_a_single_ala_label_when_there_is_only_one_winger(): void
    {
        $game = $this->makeGame();
        $captain = User::factory()->create(['name' => 'Capitão', 'position' => Position::FIXED]);
        $winger = User::factory()->create(['name' => 'Ala Único', 'position' => Position::WINGER]);

        $team = $this->makeTeam($game, TeamColor::BLUE, $captain);
        $this->addPick($game, $team, $winger);

        $text = app(DraftNarrationBuilder::class)->build($game->fresh(['teams.captain', 'draftPicks.pickedUser']), $team->fresh('captain'), NarratorVoice::LULA);

        $this->assertStringContainsString('Ala: Ala Único.', $text);
        $this->assertStringNotContainsString('Ala esquerdo', $text);
        $this->assertStringNotContainsString('Ala direito', $text);
    }

    public function test_it_appends_current_champion_suffix(): void
    {
        $previous = $this->makeGame(GameStatus::DONE);
        $champion = User::factory()->create(['name' => 'Costinha', 'position' => Position::PIVOT]);
        $previousCaptain = User::factory()->create(['position' => Position::FIXED]);
        $previousTeam = $this->makeTeam($previous, TeamColor::GREEN, $previousCaptain, score: 4);
        $this->makeTeam($previous, TeamColor::YELLOW, User::factory()->create(), score: 1);
        $this->makeTeam($previous, TeamColor::BLUE, User::factory()->create(), score: 0);
        $this->addPick($previous, $previousTeam, $champion);

        $game = $this->makeGame();
        $captain = User::factory()->create(['name' => 'Novo Capitão', 'position' => Position::FIXED]);
        $team = $this->makeTeam($game, TeamColor::YELLOW, $captain);
        $this->addPick($game, $team, $champion);

        $text = app(DraftNarrationBuilder::class)->build($game->fresh(['teams.captain', 'draftPicks.pickedUser']), $team->fresh('captain'), NarratorVoice::LULA);

        $this->assertStringContainsString('Pivô: Costinha, o atual campeão da QNF.', $text);
        $this->assertStringNotContainsString('Novo Capitão, o atual campeão', $text);
    }

    public function test_it_uses_the_bolsonaro_closing_line(): void
    {
        $game = $this->makeGame();
        $captain = User::factory()->create(['name' => 'Capitão', 'position' => Position::FIXED]);
        $team = $this->makeTeam($game, TeamColor::YELLOW, $captain);

        $text = app(DraftNarrationBuilder::class)->build($game->fresh(['teams.captain', 'draftPicks.pickedUser']), $team->fresh('captain'), NarratorVoice::BOLSONARO);

        $this->assertStringContainsString('eu vou fazer um churrasco na QNF, e ponto final.', $text);
        $this->assertStringNotContainsString('picanha', $text);
    }

    public function test_it_strips_markdown_and_hyphens_from_names(): void
    {
        $game = $this->makeGame();
        $captain = User::factory()->create(['name' => '*Jean-Pierre*', 'position' => Position::FIXED]);
        $team = $this->makeTeam($game, TeamColor::YELLOW, $captain);

        $text = app(DraftNarrationBuilder::class)->build($game->fresh(['teams.captain', 'draftPicks.pickedUser']), $team->fresh('captain'), NarratorVoice::LULA);

        $this->assertStringContainsString('Fixo: Jean Pierre.', $text);
        $this->assertStringNotContainsString('*', $text);
        $this->assertStringNotContainsString('Jean-Pierre', $text);
    }

    private function makeGame(GameStatus $status = GameStatus::DRAFTED): Game
    {
        return Game::create([
            'date' => now()->addDays(Game::count())->toDateString(),
            'opens_at' => now(),
            'status' => $status,
            'round' => Game::count() + 1,
        ]);
    }

    private function makeTeam(Game $game, TeamColor $color, User $captain, ?int $score = null): Team
    {
        return Team::create([
            'game_id' => $game->id,
            'color' => $color,
            'captain_user_id' => $captain->id,
            'pick_order' => $game->teams()->count() + 1,
            'score' => $score,
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
