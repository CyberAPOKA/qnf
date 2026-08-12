<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\RecSaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecSaveCooldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_first_save_within_ten_seconds_is_accepted(): void
    {
        $game = $this->makeGame();
        [$userA, $userB, $userC] = User::factory()->count(3)->create();

        $this->actingAs($userA)
            ->postJson(route('games.rec.start', $game), [
                'recorder_id' => 'phone-a',
                'camera_tag' => 'A1',
            ])
            ->assertOk();

        $this->actingAs($userB)
            ->postJson(route('games.rec.start', $game), [
                'recorder_id' => 'phone-b',
                'camera_tag' => 'A2',
            ])
            ->assertOk();

        $first = $this->actingAs($userA)->postJson(route('games.rec.save', $game));
        $second = $this->actingAs($userB)->postJson(route('games.rec.save', $game));
        $third = $this->actingAs($userC)->postJson(route('games.rec.save', $game));

        $first->assertOk()
            ->assertJsonPath('cooldown_seconds', 10)
            ->assertJsonPath('expected_recorders', 2);

        $second->assertStatus(429)
            ->assertJsonPath('retry_after', 10);

        $third->assertStatus(429);

        $this->assertSame(1, RecSaveRequest::query()->where('game_id', $game->id)->count());
        $this->assertSame($userA->id, RecSaveRequest::query()->first()->triggered_by);
    }

    public function test_a_new_save_is_allowed_after_the_cooldown(): void
    {
        $game = $this->makeGame();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('games.rec.start', $game), [
                'recorder_id' => 'phone-a',
                'camera_tag' => 'B1',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('games.rec.save', $game))
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('games.rec.save', $game))
            ->assertStatus(429);

        $this->travel(11)->seconds();

        $this->actingAs($user)
            ->postJson(route('games.rec.save', $game))
            ->assertOk();

        $this->assertSame(2, RecSaveRequest::query()->where('game_id', $game->id)->count());
    }

    private function makeGame(): Game
    {
        return Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::DRAFTED,
        ]);
    }
}
