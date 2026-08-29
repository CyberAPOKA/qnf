<?php

namespace Tests\Feature\Admin;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwapAndRemovePlayersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_remove_subscribed_player(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create();
        $game = $this->createOpenGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from('/')
            ->post(route('games.remove-player', $game), [
                'user_id' => $player->id,
            ])
            ->assertRedirect('/');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->first()->dropped_out
        );
    }

    public function test_admin_can_remove_player_while_drafting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create();
        $game = $this->createOpenGame(['status' => GameStatus::DRAFTING]);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from('/')
            ->post(route('games.remove-player', $game), [
                'user_id' => $player->id,
            ])
            ->assertRedirect('/');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->first()->dropped_out
        );
    }

    public function test_non_admin_cannot_remove_player(): void
    {
        $user = User::factory()->create();
        $player = User::factory()->create();
        $game = $this->createOpenGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('games.remove-player', $game), [
                'user_id' => $player->id,
            ])
            ->assertForbidden();
    }

    public function test_admin_replaces_player_not_in_the_round(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $from = User::factory()->create(['name' => 'Joao']);
        $to = User::factory()->create(['name' => 'Pedro']);
        $game = $this->createOpenGame(['round' => 12, 'status' => GameStatus::DRAFTING]);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $from->id,
            'joined_at' => now()->subHour(),
            'points' => 3,
        ]);

        Team::create([
            'game_id' => $game->id,
            'color' => TeamColor::GREEN,
            'pick_order' => 1,
            'captain_user_id' => $from->id,
        ]);

        DraftPick::create([
            'game_id' => $game->id,
            'round' => 1,
            'pick_in_round' => 1,
            'team_color' => TeamColor::YELLOW,
            'picked_user_id' => $from->id,
            'picked_at' => now(),
        ]);

        Payment::create([
            'game_id' => $game->id,
            'user_id' => $from->id,
            'amount' => 800,
            'pix_payload' => 'pix-joao',
        ]);

        $this->actingAs($admin)
            ->from('/')
            ->post(route('games.swap-players', $game), [
                'user_id' => $from->id,
                'replacement_user_id' => $to->id,
            ])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('game_players', [
            'game_id' => $game->id,
            'user_id' => $from->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('game_players', [
            'game_id' => $game->id,
            'user_id' => $to->id,
            'points' => 3,
            'dropped_out' => false,
        ]);
        $this->assertDatabaseHas('teams', [
            'game_id' => $game->id,
            'captain_user_id' => $to->id,
        ]);
        $this->assertDatabaseHas('draft_picks', [
            'game_id' => $game->id,
            'picked_user_id' => $to->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'game_id' => $game->id,
            'user_id' => $to->id,
            'pix_payload' => 'pix-joao',
        ]);
    }

    public function test_admin_cannot_replace_with_player_already_in_the_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $from = User::factory()->create(['name' => 'Joao']);
        $to = User::factory()->create(['name' => 'Pedro']);
        $game = $this->createOpenGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $from->id,
            'joined_at' => now()->subHours(2),
        ]);
        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $to->id,
            'joined_at' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->from('/')
            ->post(route('games.swap-players', $game), [
                'user_id' => $from->id,
                'replacement_user_id' => $to->id,
            ])
            ->assertRedirect('/')
            ->assertSessionHasErrors('replacement_user_id');
    }

    public function test_admin_cannot_replace_line_player_with_goalkeeper(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $from = User::factory()->create(['position' => Position::WINGER]);
        $to = User::factory()->create(['position' => Position::GOALKEEPER]);
        $game = $this->createOpenGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $from->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from('/')
            ->post(route('games.swap-players', $game), [
                'user_id' => $from->id,
                'replacement_user_id' => $to->id,
            ])
            ->assertRedirect('/')
            ->assertSessionHasErrors('replacement_user_id');
    }

    public function test_admin_can_replace_goalkeeper_with_another_goalkeeper(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $from = User::factory()->create(['position' => Position::GOALKEEPER]);
        $to = User::factory()->create(['position' => Position::GOALKEEPER]);
        $game = $this->createOpenGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $from->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from('/')
            ->post(route('games.swap-players', $game), [
                'user_id' => $from->id,
                'replacement_user_id' => $to->id,
            ])
            ->assertRedirect('/');

        $this->assertDatabaseHas('game_players', [
            'game_id' => $game->id,
            'user_id' => $to->id,
            'dropped_out' => false,
        ]);
        $this->assertDatabaseMissing('game_players', [
            'game_id' => $game->id,
            'user_id' => $from->id,
            'deleted_at' => null,
        ]);
    }

    public function test_non_admin_cannot_swap_players(): void
    {
        $user = User::factory()->create();
        $from = User::factory()->create();
        $to = User::factory()->create();
        $game = $this->createOpenGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $from->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('games.swap-players', $game), [
                'user_id' => $from->id,
                'replacement_user_id' => $to->id,
            ])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOpenGame(array $overrides = []): Game
    {
        return Game::create(array_merge([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'round' => 10,
            'status' => GameStatus::OPEN,
        ], $overrides));
    }
}
