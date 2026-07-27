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

    public function test_it_does_not_create_next_round_on_monday_when_last_game_is_done(): void
    {
        $doneGame = Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-17 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $monday = CarbonImmutable::parse('2026-07-20 22:30:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $monday);

        $this->assertSame($doneGame->id, $game->id);
        $this->assertSame(1, Game::count());
    }

    public function test_it_creates_next_round_on_thursday_at_noon(): void
    {
        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-17 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $thursdayNoon = CarbonImmutable::parse('2026-07-23 12:00:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $thursdayNoon);

        $this->assertSame(18, $game->round);
        $this->assertSame(GameStatus::SCHEDULED, $game->status);
        $this->assertSame('2026-07-27', $game->date->toDateString());
        $this->assertSame('2026-07-24 17:00:00', $game->opens_at->timezone(GameService::TZ)->format('Y-m-d H:i:s'));
    }

    public function test_it_does_not_create_next_round_before_thursday_noon(): void
    {
        $doneGame = Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-17 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $thursdayMorning = CarbonImmutable::parse('2026-07-23 11:59:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $thursdayMorning);

        $this->assertSame($doneGame->id, $game->id);
        $this->assertSame(1, Game::count());
    }

    public function test_it_opens_scheduled_game_on_friday_at_17(): void
    {
        Game::create([
            'date' => '2026-07-27',
            'opens_at' => '2026-07-24 17:00:00',
            'round' => 18,
            'status' => GameStatus::SCHEDULED,
        ]);

        $service = app(GameService::class);

        $before = $service->openGameIfNeeded(CarbonImmutable::parse('2026-07-24 16:59:00', GameService::TZ));
        $this->assertNull($before);

        $opened = $service->openGameIfNeeded(CarbonImmutable::parse('2026-07-24 17:00:00', GameService::TZ));
        $this->assertNotNull($opened);
        $this->assertSame(GameStatus::OPEN, $opened->status);
    }

    public function test_it_returns_active_game_instead_of_creating(): void
    {
        $activeGame = Game::create([
            'date' => '2026-07-13',
            'opens_at' => '2026-07-10 17:00:00',
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
