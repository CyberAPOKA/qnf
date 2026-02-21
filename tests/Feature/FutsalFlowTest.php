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

    public function test_join_never_goes_over_fifteen_players(): void
    {
        $users = User::factory()->count(16)->create();
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::OPEN,
        ]);

        foreach ($users->take(15) as $user) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'joined_at' => now(),
            ]);
        }

        $this->actingAs($users->last())
            ->post(route('games.join', $game))
            ->assertSessionHasErrors('join');

        $this->assertEquals(15, GamePlayer::where('game_id', $game->id)->count());
    }

    public function test_draw_captains_never_selects_goalkeeper(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
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

        $this->actingAs($admin)->post(route('games.draw-captains', $game))->assertRedirect();

        $captainIds = Team::where('game_id', $game->id)->pluck('captain_user_id');
        $this->assertCount(3, $captainIds);
        $this->assertFalse(User::whereIn('id', $captainIds)->where('position', Position::GOALKEEPER)->exists());
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
        $admin = User::factory()->create(['role' => 'admin']);

        $players = User::factory()->count(12)->create();
        foreach ($players as $player) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'joined_at' => now(),
            ]);
        }

        foreach ($players as $player) {
            $service->makePick($game, $player->id, $admin->id);
            $game->refresh();
        }

        $this->assertEquals(GameStatus::DONE, $game->status);
        $this->assertEquals(12, DraftPick::where('game_id', $game->id)->count());
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
