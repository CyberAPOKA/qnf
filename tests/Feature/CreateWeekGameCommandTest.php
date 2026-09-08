<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameSchedule;
use App\Services\GameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateWeekGameCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_game_from_due_schedule(): void
    {
        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        GameSchedule::create([
            'round' => 18,
            'creates_at' => now(GameService::TZ)->subMinute(),
            'opens_at' => now(GameService::TZ)->addHours(5),
            'starts_at' => now(GameService::TZ)->addDays(4)->setTime(21, 0),
        ]);

        $this->artisan('futsal:create-week-game')
            ->expectsOutputToContain('Jogo da rodada 18 criado')
            ->assertSuccessful();

        $this->assertSame(2, Game::count());
        $this->assertSame(18, Game::orderByDesc('id')->first()->round);
        $this->assertNotNull(GameSchedule::first()->game_id);
    }

    public function test_command_does_not_create_before_creates_at(): void
    {
        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        GameSchedule::create([
            'round' => 18,
            'creates_at' => now(GameService::TZ)->addHour(),
            'opens_at' => now(GameService::TZ)->addHours(5),
            'starts_at' => now(GameService::TZ)->addDays(4)->setTime(21, 0),
        ]);

        $this->artisan('futsal:create-week-game')
            ->expectsOutputToContain('Sem agendamento pendente')
            ->assertSuccessful();

        $this->assertSame(1, Game::count());
    }

    public function test_force_creates_game_before_creates_at(): void
    {
        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        GameSchedule::create([
            'round' => 18,
            'creates_at' => now(GameService::TZ)->addHour(),
            'opens_at' => now(GameService::TZ)->addHours(5),
            'starts_at' => now(GameService::TZ)->addDays(4)->setTime(21, 0),
        ]);

        $this->artisan('futsal:create-week-game', ['--force' => true])
            ->expectsOutputToContain('Jogo da rodada 18 criado')
            ->assertSuccessful();

        $this->assertSame(2, Game::count());
    }
}
