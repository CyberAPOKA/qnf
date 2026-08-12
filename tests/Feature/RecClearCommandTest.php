<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\RecClip;
use App\Models\RecSaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecClearCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_only_rec_data_for_the_given_game(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $game = $this->makeGame('2026-08-12');
        $otherGame = $this->makeGame('2026-08-19');

        $save = RecSaveRequest::create([
            'game_id' => $game->id,
            'triggered_by' => $user->id,
            'uuid' => (string) Str::uuid(),
        ]);
        $otherSave = RecSaveRequest::create([
            'game_id' => $otherGame->id,
            'triggered_by' => $user->id,
            'uuid' => (string) Str::uuid(),
        ]);

        $clipPath = "rec/{$game->id}/{$save->uuid}/clip.webm";
        $otherPath = "rec/{$otherGame->id}/{$otherSave->uuid}/clip.webm";
        Storage::disk('public')->put($clipPath, 'video-a');
        Storage::disk('public')->put($otherPath, 'video-b');

        RecClip::create([
            'rec_save_request_id' => $save->id,
            'game_id' => $game->id,
            'user_id' => $user->id,
            'recorder_id' => 'phone-a',
            'file_path' => $clipPath,
            'duration_seconds' => 30,
        ]);
        RecClip::create([
            'rec_save_request_id' => $otherSave->id,
            'game_id' => $otherGame->id,
            'user_id' => $user->id,
            'recorder_id' => 'phone-b',
            'file_path' => $otherPath,
            'duration_seconds' => 30,
        ]);

        $this->artisan('rec:clear', [
            'game_id' => $game->id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('rec_clips', ['game_id' => $game->id]);
        $this->assertDatabaseMissing('rec_save_requests', ['game_id' => $game->id]);
        $this->assertDatabaseHas('rec_clips', ['game_id' => $otherGame->id]);
        $this->assertDatabaseHas('rec_save_requests', ['game_id' => $otherGame->id]);
        $this->assertDatabaseHas('games', ['id' => $game->id]);

        Storage::disk('public')->assertMissing($clipPath);
        Storage::disk('public')->assertExists($otherPath);
    }

    private function makeGame(string $date): Game
    {
        return Game::create([
            'date' => $date,
            'opens_at' => now(),
            'status' => GameStatus::DRAFTED,
        ]);
    }
}
