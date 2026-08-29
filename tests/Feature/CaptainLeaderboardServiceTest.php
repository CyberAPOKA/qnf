<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\TeamColor;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\Services\CaptainLeaderboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptainLeaderboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_includes_the_current_drafting_round(): void
    {
        $repeatCaptain = User::factory()->create(['name' => 'Ana']);
        $doneCaptainA = User::factory()->create(['name' => 'Bruno']);
        $doneCaptainB = User::factory()->create(['name' => 'Caio']);
        $currentCaptain = User::factory()->create(['name' => 'Diego']);

        $doneGame = $this->createGame(10, GameStatus::DONE, '2026-07-20');
        $this->addCaptains($doneGame, [$repeatCaptain, $doneCaptainA, $doneCaptainB]);

        $currentGame = $this->createGame(11, GameStatus::DRAFTING, '2026-07-27');
        $this->addCaptains($currentGame, [$repeatCaptain, $currentCaptain, $doneCaptainA]);

        $top = app(CaptainLeaderboardService::class)->top(10);

        $this->assertSame([
            ['id' => $repeatCaptain->id, 'name' => 'Ana', 'count' => 2, 'rounds' => [10, 11]],
            ['id' => $doneCaptainA->id, 'name' => 'Bruno', 'count' => 2, 'rounds' => [10, 11]],
            ['id' => $doneCaptainB->id, 'name' => 'Caio', 'count' => 1, 'rounds' => [10]],
            ['id' => $currentCaptain->id, 'name' => 'Diego', 'count' => 1, 'rounds' => [11]],
        ], $top);
    }

    public function test_it_limits_to_ten_captains(): void
    {
        $doneGame = $this->createGame(1, GameStatus::DONE, '2026-06-01');
        $currentGame = $this->createGame(2, GameStatus::DRAFTED, '2026-06-08');

        $first = User::factory()->create(['name' => 'Alpha']);
        $this->addCaptains($doneGame, [
            $first,
            User::factory()->create(['name' => 'Bravo']),
            User::factory()->create(['name' => 'Charlie']),
        ]);

        $extra = User::factory()->count(9)->sequence(
            fn ($sequence) => ['name' => 'Jogador '.str_pad((string) ($sequence->index + 1), 2, '0', STR_PAD_LEFT)],
        )->create();

        $this->addCaptains($currentGame, [$first, $extra[0], $extra[1]]);

        foreach ($extra->skip(2)->values() as $index => $user) {
            $game = $this->createGame($index + 3, GameStatus::DONE, now()->addDays($index + 10)->toDateString());
            Team::create([
                'game_id' => $game->id,
                'color' => TeamColor::GREEN,
                'pick_order' => 1,
                'captain_user_id' => $user->id,
            ]);
        }

        $top = app(CaptainLeaderboardService::class)->top(10);

        $this->assertCount(10, $top);
        $this->assertSame('Alpha', $top[0]['name']);
        $this->assertSame(2, $top[0]['count']);
        $this->assertContains(2, $top[0]['rounds']);
    }

    private function createGame(int $round, GameStatus $status, string $date): Game
    {
        return Game::create([
            'date' => $date,
            'opens_at' => now(),
            'round' => $round,
            'status' => $status,
        ]);
    }

    /**
     * @param  list<User>  $captains
     */
    private function addCaptains(Game $game, array $captains): void
    {
        $colors = [TeamColor::GREEN, TeamColor::YELLOW, TeamColor::BLUE];

        foreach ($captains as $index => $captain) {
            Team::create([
                'game_id' => $game->id,
                'color' => $colors[$index],
                'pick_order' => $index + 1,
                'captain_user_id' => $captain->id,
            ]);
        }
    }
}
