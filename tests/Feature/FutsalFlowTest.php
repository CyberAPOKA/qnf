<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftService;
use App\Support\GamePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FutsalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_allow_join_when_game_is_not_open(): void
    {
        $user = User::factory()->create();
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::SCHEDULED,
        ]);

        $this->actingAs($user)
            ->post(route('games.join', $game))
            ->assertSessionHasErrors('join');
    }

    public function test_game_payload_handles_missing_teams_before_draw(): void
    {
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::OPEN,
        ]);

        $payload = GamePayload::fromGame($game, app(DraftService::class));

        $this->assertNull($payload['teams']['green']['captain']);
        $this->assertNull($payload['teams']['yellow']['captain']);
        $this->assertNull($payload['teams']['blue']['captain']);
    }

    public function test_goalkeeper_cannot_self_join(): void
    {
        $goalkeeper = User::factory()->create(['position' => Position::GOALKEEPER]);
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::OPEN,
        ]);

        $this->actingAs($goalkeeper)
            ->post(route('games.join', $game))
            ->assertSessionHasErrors('join');

        $this->assertEquals(0, GamePlayer::where('game_id', $game->id)->count());
    }

    public function test_line_player_join_capped_at_twelve(): void
    {
        $linePlayers = User::factory()->count(13)->create(['position' => Position::WINGER]);
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::OPEN,
        ]);

        foreach ($linePlayers->take(12) as $user) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'joined_at' => now(),
            ]);
        }

        $this->actingAs($linePlayers->last())
            ->post(route('games.join', $game))
            ->assertSessionHasErrors('join');

        $this->assertEquals(12, GamePlayer::where('game_id', $game->id)->count());
    }

    public function test_draw_captains_never_selects_goalkeeper(): void
    {
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::FULL,
        ]);

        $goalkeepers = User::factory()->count(3)->create(['position' => Position::GOALKEEPER]);
        $linePlayers = User::factory()->count(12)->create(['position' => Position::WINGER]);

        foreach ($goalkeepers->merge($linePlayers) as $player) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'joined_at' => now(),
            ]);
        }

        $draftService = app(DraftService::class);
        $draftService->drawCaptains($game);

        $captainIds = Team::where('game_id', $game->id)->pluck('captain_user_id');
        $this->assertCount(3, $captainIds);
        $this->assertFalse(User::whereIn('id', $captainIds)->where('position', Position::GOALKEEPER)->exists());
    }

    public function test_draw_captains_never_selects_guest(): void
    {
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::FULL,
        ]);

        $guests = User::factory()->count(3)->create(['position' => Position::WINGER, 'guest' => true]);
        $goalkeepers = User::factory()->count(3)->create(['position' => Position::GOALKEEPER, 'guest' => false]);
        $regulars = User::factory()->count(9)->create(['position' => Position::WINGER, 'guest' => false]);

        foreach ($guests->merge($goalkeepers)->merge($regulars) as $player) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'joined_at' => now(),
            ]);
        }

        $draftService = app(DraftService::class);
        $draftService->drawCaptains($game);

        $captainIds = Team::where('game_id', $game->id)->pluck('captain_user_id');
        $this->assertCount(3, $captainIds);
        $this->assertFalse(User::whereIn('id', $captainIds)->where('guest', true)->exists());
    }

    public function test_snake_order_is_deterministic(): void
    {
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::DRAFTING,
        ]);

        $service = app(DraftService::class);

        $this->assertEquals(TeamColor::GREEN, $service->currentTurnColor($game));

        DraftPick::create([
            'game_id' => $game->id,
            'round' => 1,
            'pick_in_round' => 1,
            'team_color' => TeamColor::GREEN,
            'picked_user_id' => User::factory()->create()->id,
            'picked_at' => now(),
        ]);

        $this->assertEquals(TeamColor::YELLOW, $service->currentTurnColor($game));
    }

    public function test_pick_out_of_turn_is_rejected(): void
    {
        $game = $this->draftingGameWithTeams();
        $captainYellow = Team::where('game_id', $game->id)->where('color', TeamColor::YELLOW)->first()->captain;
        $target = User::factory()->create();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $target->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($captainYellow)
            ->post(route('games.pick', $game), ['user_id' => $target->id])
            ->assertForbidden();
    }

    public function test_game_is_marked_done_after_twelve_picks(): void
    {
        $game = $this->draftingGameWithTeams();
        $service = app(DraftService::class);

        $captainsByColor = [];
        foreach ($game->teams as $team) {
            $captainsByColor[$team->color->value] = $team->captain_user_id;
        }

        // 9 line players + 3 goalkeepers; goalkeepers placed at picks 9,10,11
        // so each team's 4th pick is a goalkeeper (1 per team)
        $linePlayers = User::factory()->count(9)->create();
        $goalkeepers = User::factory()->count(3)->create(['position' => Position::GOALKEEPER]);
        $players = $linePlayers->concat($goalkeepers);

        foreach ($players as $player) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'joined_at' => now(),
            ]);
        }

        foreach ($players as $player) {
            $turnColor = $service->currentTurnColor($game);
            $captainId = $captainsByColor[$turnColor->value];
            $service->makePick($game, $player->id, $captainId);
            $game->refresh();
        }

        $this->assertEquals(GameStatus::DONE, $game->status);
        $this->assertEquals(12, DraftPick::where('game_id', $game->id)->count());

        foreach ($game->teams()->get() as $team) {
            $this->assertNotNull($team->first_pick_user_id, "Team {$team->color->value} should have a first pick");
        }
    }

    public function test_captain_cannot_pick_second_goalkeeper(): void
    {
        $game = $this->draftingGameWithTeams();
        $service = app(DraftService::class);

        $greenCaptainId = Team::where('game_id', $game->id)
            ->where('color', TeamColor::GREEN)->first()->captain_user_id;

        $gk1 = User::factory()->create(['position' => Position::GOALKEEPER]);
        $gk2 = User::factory()->create(['position' => Position::GOALKEEPER]);

        foreach ([$gk1, $gk2] as $gk) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $gk->id,
                'joined_at' => now(),
            ]);
        }

        // First pick (GREEN) — goalkeeper allowed
        $service->makePick($game, $gk1->id, $greenCaptainId);
        $game->refresh();

        // Skip YELLOW and BLUE turns
        $filler = User::factory()->count(2)->create();
        foreach ($filler as $f) {
            GamePlayer::create(['game_id' => $game->id, 'user_id' => $f->id, 'joined_at' => now()]);
        }
        foreach ($filler as $f) {
            $turnColor = $service->currentTurnColor($game);
            $captainId = Team::where('game_id', $game->id)->where('color', $turnColor)->first()->captain_user_id;
            $service->makePick($game, $f->id, $captainId);
            $game->refresh();
        }

        // Now it's BLUE's turn (pick 3), then YELLOW (pick 4), then GREEN (pick 5) — wait, let me check the snake.
        // SNAKE: GREEN, YELLOW, BLUE, BLUE, YELLOW, GREEN — so pick 3 is BLUE, pick 4 is BLUE, pick 5 is YELLOW
        // We need GREEN's next turn which is pick 5... no.
        // Pick 0: GREEN (done), Pick 1: YELLOW (done), Pick 2: BLUE (done)
        // Pick 3: BLUE, Pick 4: YELLOW, Pick 5: GREEN — GREEN's turn again

        // Skip BLUE (pick 3) and YELLOW (pick 4)
        $filler2 = User::factory()->count(2)->create();
        foreach ($filler2 as $f) {
            GamePlayer::create(['game_id' => $game->id, 'user_id' => $f->id, 'joined_at' => now()]);
        }
        foreach ($filler2 as $f) {
            $turnColor = $service->currentTurnColor($game);
            $captainId = Team::where('game_id', $game->id)->where('color', $turnColor)->first()->captain_user_id;
            $service->makePick($game, $f->id, $captainId);
            $game->refresh();
        }

        // Now it's GREEN's turn (pick 5) — second goalkeeper should be rejected
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->makePick($game, $gk2->id, $greenCaptainId);
    }

    public function test_captain_must_pick_goalkeeper_after_three_line_players(): void
    {
        $game = $this->draftingGameWithTeams();
        $service = app(DraftService::class);

        $captainsByColor = [];
        foreach ($game->teams as $team) {
            $captainsByColor[$team->color->value] = $team->captain_user_id;
        }

        // Create 9 line players + 3 goalkeepers
        $linePlayers = User::factory()->count(9)->create(['position' => Position::WINGER]);
        $goalkeepers = User::factory()->count(3)->create(['position' => Position::GOALKEEPER]);
        $allPlayers = $linePlayers->merge($goalkeepers);

        foreach ($allPlayers as $player) {
            GamePlayer::create(['game_id' => $game->id, 'user_id' => $player->id, 'joined_at' => now()]);
        }

        // Make 9 picks (3 line per team): picks 0-8 in snake order
        // SNAKE: G,Y,B, B,Y,G, G,Y,B — each team gets 3 line players
        for ($i = 0; $i < 9; $i++) {
            $turnColor = $service->currentTurnColor($game);
            $captainId = $captainsByColor[$turnColor->value];
            $service->makePick($game, $linePlayers[$i]->id, $captainId);
            $game->refresh();
        }

        // Pick 9 is BLUE's turn — team already has 3 line players, must pick goalkeeper
        $turnColor = $service->currentTurnColor($game);
        $captainId = $captainsByColor[$turnColor->value];

        // Trying a line player should fail
        $extraLine = User::factory()->create(['position' => Position::PIVOT]);
        GamePlayer::create(['game_id' => $game->id, 'user_id' => $extraLine->id, 'joined_at' => now()]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->makePick($game, $extraLine->id, $captainId);
    }

    private function draftingGameWithTeams(): Game
    {
        $captains = User::factory()->count(3)->create();
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::DRAFTING,
        ]);

        foreach ($captains as $captain) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $captain->id,
                'joined_at' => now(),
            ]);
        }

        Team::create([
            'game_id' => $game->id,
            'color' => TeamColor::GREEN,
            'captain_user_id' => $captains[0]->id,
            'pick_order' => 1,
        ]);
        Team::create([
            'game_id' => $game->id,
            'color' => TeamColor::YELLOW,
            'captain_user_id' => $captains[1]->id,
            'pick_order' => 2,
        ]);
        Team::create([
            'game_id' => $game->id,
            'color' => TeamColor::BLUE,
            'captain_user_id' => $captains[2]->id,
            'pick_order' => 3,
        ]);

        return $game;
    }
}
