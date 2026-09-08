<?php

namespace Tests\Feature\Admin;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameSchedule;
use App\Models\User;
use App\Services\GameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminMercadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_mercado(): void
    {
        $this->get(route('admin.mercado'))->assertRedirect(route('login'));
    }

    public function test_player_cannot_view_mercado(): void
    {
        $player = User::factory()->create(['role' => 'player']);

        $this->actingAs($player)
            ->get(route('admin.mercado'))
            ->assertForbidden();
    }

    public function test_admin_sees_default_schedule_for_next_round(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create(['role' => 'admin']);

        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mercado'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AdminMercado')
                ->where('has_pending', false)
                ->where('schedule.round', 18)
                ->has('schedule.creates_at')
                ->has('schedule.opens_at')
                ->has('schedule.starts_at')
            );
    }

    public function test_admin_can_save_next_round_schedule(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.mercado'))
            ->post(route('admin.mercado.store'), [
                'creates_at' => '2026-07-23T12:00',
                'opens_at' => '2026-07-24T17:00',
                'starts_at' => '2026-07-27T21:00',
            ])
            ->assertRedirect(route('admin.mercado'))
            ->assertSessionHasNoErrors();

        $schedule = GameSchedule::first();

        $this->assertNotNull($schedule);
        $this->assertSame(18, $schedule->round);
        $this->assertSame('2026-07-23 12:00:00', $schedule->creates_at->timezone(GameService::TZ)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-24 17:00:00', $schedule->opens_at->timezone(GameService::TZ)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-27 21:00:00', $schedule->starts_at->timezone(GameService::TZ)->format('Y-m-d H:i:s'));
        $this->assertSame($admin->id, $schedule->created_by);
    }

    public function test_admin_cannot_choose_the_round_number(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 25,
            'status' => GameStatus::DONE,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.mercado'))
            ->post(route('admin.mercado.store'), [
                'round' => 99,
                'creates_at' => '2026-07-23T12:00',
                'opens_at' => '2026-07-24T17:00',
                'starts_at' => '2026-07-27T21:00',
            ])
            ->assertRedirect(route('admin.mercado'))
            ->assertSessionHasNoErrors();

        $this->assertSame(26, GameSchedule::first()->round);
    }

    public function test_admin_can_force_create_game_from_schedule(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        GameSchedule::create([
            'round' => 18,
            'creates_at' => '2026-07-23 12:00:00',
            'opens_at' => '2026-07-23 17:00:00',
            'starts_at' => '2026-07-27 21:00:00',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.mercado'))
            ->post(route('admin.mercado.create-game'))
            ->assertRedirect(route('admin.mercado'))
            ->assertSessionHasNoErrors();

        $game = Game::where('round', 18)->first();

        $this->assertNotNull($game);
        $this->assertSame(GameStatus::SCHEDULED, $game->status);
        $this->assertSame($game->id, GameSchedule::first()->game_id);
    }

    public function test_admin_can_update_current_scheduled_game_times(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $game = Game::create([
            'date' => '2026-07-27',
            'starts_at' => '2026-07-27 21:00:00',
            'opens_at' => '2026-07-23 17:00:00',
            'round' => 18,
            'status' => GameStatus::SCHEDULED,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.mercado'))
            ->post(route('admin.mercado.update-game', $game), [
                'opens_at' => '2026-07-23T18:00',
                'starts_at' => '2026-07-27T21:30',
            ])
            ->assertRedirect(route('admin.mercado'))
            ->assertSessionHasNoErrors();

        $game->refresh();

        $this->assertSame('2026-07-23 18:00:00', $game->opens_at->timezone(GameService::TZ)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-27 21:30:00', $game->starts_at->timezone(GameService::TZ)->format('Y-m-d H:i:s'));
    }
}
