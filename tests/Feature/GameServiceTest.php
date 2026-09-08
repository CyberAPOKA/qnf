<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameSchedule;
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

    public function test_it_does_not_create_next_round_on_thursday_noon_without_schedule(): void
    {
        $doneGame = Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $thursdayNoon = CarbonImmutable::parse('2026-07-23 12:00:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $thursdayNoon);

        $this->assertSame($doneGame->id, $game->id);
        $this->assertSame(1, Game::count());
    }

    public function test_it_creates_next_round_from_schedule_at_creates_at(): void
    {
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
        ]);

        $service = app(GameService::class);

        $before = $service->createScheduledGameIfNeeded(now: CarbonImmutable::parse('2026-07-23 11:59:00', GameService::TZ));
        $this->assertNull($before);
        $this->assertSame(1, Game::count());

        $game = $service->createScheduledGameIfNeeded(now: CarbonImmutable::parse('2026-07-23 12:00:00', GameService::TZ));

        $this->assertNotNull($game);
        $this->assertSame(18, $game->round);
        $this->assertSame(GameStatus::SCHEDULED, $game->status);
        $this->assertSame('2026-07-27', $game->date->toDateString());
        $this->assertSame('2026-07-23 17:00:00', $game->opens_at->timezone(GameService::TZ)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-27 21:00:00', $game->starts_at->timezone(GameService::TZ)->format('Y-m-d H:i:s'));
        $this->assertNotNull(GameSchedule::first()->game_id);
    }

    public function test_it_does_not_create_next_round_before_thursday_noon(): void
    {
        $doneGame = Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $thursdayMorning = CarbonImmutable::parse('2026-07-23 11:59:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $thursdayMorning);

        $this->assertSame($doneGame->id, $game->id);
        $this->assertSame(1, Game::count());
    }

    public function test_it_opens_scheduled_game_when_opens_at_arrives(): void
    {
        Game::create([
            'date' => '2026-07-27',
            'opens_at' => '2026-07-23 17:00:00',
            'round' => 18,
            'status' => GameStatus::SCHEDULED,
        ]);

        $service = app(GameService::class);

        $before = $service->openGameIfNeeded(CarbonImmutable::parse('2026-07-23 16:59:00', GameService::TZ));
        $this->assertNull($before);

        $opened = $service->openGameIfNeeded(CarbonImmutable::parse('2026-07-23 17:00:00', GameService::TZ));
        $this->assertNotNull($opened);
        $this->assertSame(GameStatus::OPEN, $opened->status);
    }

    public function test_it_returns_active_game_instead_of_creating(): void
    {
        $activeGame = Game::create([
            'date' => '2026-07-13',
            'opens_at' => '2026-07-09 17:00:00',
            'round' => 17,
            'status' => GameStatus::DRAFTED,
        ]);

        GameSchedule::create([
            'round' => 18,
            'creates_at' => '2026-07-16 12:00:00',
            'opens_at' => '2026-07-16 17:00:00',
            'starts_at' => '2026-07-20 21:00:00',
        ]);

        $monday = CarbonImmutable::parse('2026-07-20 10:00:00', GameService::TZ);
        $service = app(GameService::class);

        $game = $service->getOrCreateThisWeekGame(null, $monday);

        $this->assertSame($activeGame->id, $game->id);
        $this->assertSame(1, Game::count());
    }

    public function test_default_schedule_is_thursday_noon_friday_17_and_monday_21(): void
    {
        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 17,
            'status' => GameStatus::DONE,
        ]);

        $monday = CarbonImmutable::parse('2026-07-20 22:00:00', GameService::TZ);
        $defaults = app(GameService::class)->defaultSchedule($monday);

        $this->assertSame(18, $defaults['round']);
        $this->assertSame('2026-07-23 12:00:00', $defaults['creates_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-24 17:00:00', $defaults['opens_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-27 21:00:00', $defaults['starts_at']->format('Y-m-d H:i:s'));
    }

    public function test_next_round_is_the_latest_game_round_plus_one(): void
    {
        Game::create([
            'date' => '2026-07-20',
            'opens_at' => '2026-07-16 17:00:00',
            'round' => 25,
            'status' => GameStatus::DRAFTED,
        ]);

        $this->assertSame(26, app(GameService::class)->nextRound());
    }
}
