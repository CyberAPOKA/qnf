<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\GameService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_next_round_on_monday_when_last_game_is_done(): void
    {
        $doneGame = Game::create([
            'date' => '2026-07-13',
            'opens_at' => '2026-07-12 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $monday = CarbonImmutable::parse('2026-07-20 10:00:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $monday);

        $this->assertNotSame($doneGame->id, $game->id);
        $this->assertSame(18, $game->round);
        $this->assertSame(GameStatus::SCHEDULED, $game->status);
        $this->assertSame('2026-07-20', $game->date->toDateString());
    }

    public function test_it_does_not_return_done_game_as_existing_for_same_date(): void
    {
        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-19 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $monday = CarbonImmutable::parse('2026-07-20 10:00:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $monday);

        $this->assertSame(18, $game->round);
        $this->assertSame(GameStatus::SCHEDULED, $game->status);
        $this->assertSame('2026-07-27', $game->date->toDateString());
    }

    public function test_it_returns_active_game_instead_of_creating(): void
    {
        $activeGame = Game::create([
            'date' => '2026-07-13',
            'opens_at' => '2026-07-12 17:00:00',
            'round' => 17,
            'status' => GameStatus::DRAFTED,
        ]);

        $monday = CarbonImmutable::parse('2026-07-20 10:00:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $monday);

        $this->assertSame($activeGame->id, $game->id);
        $this->assertSame(1, Game::count());
    }
}
