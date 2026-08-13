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

class RecRotateClipsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_only_b1_clips_and_does_not_change_files(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $game = Game::create([
            'date' => '2026-08-12',
            'opens_at' => now(),
            'status' => GameStatus::DRAFTED,
        ]);
        $save = RecSaveRequest::create([
            'game_id' => $game->id,
            'triggered_by' => $user->id,
            'uuid' => (string) Str::uuid(),
        ]);

        $b1Path = "rec/{$game->id}/{$save->uuid}/b1.webm";
        $a1Path = "rec/{$game->id}/{$save->uuid}/a1.webm";
        Storage::disk('public')->put($b1Path, 'video-b1');
        Storage::disk('public')->put($a1Path, 'video-a1');

        RecClip::create([
            'rec_save_request_id' => $save->id,
            'game_id' => $game->id,
            'user_id' => $user->id,
            'recorder_id' => 'phone-b1',
            'camera_tag' => 'B1',
            'file_path' => $b1Path,
            'duration_seconds' => 30,
        ]);
        RecClip::create([
            'rec_save_request_id' => $save->id,
            'game_id' => $game->id,
            'user_id' => $user->id,
            'recorder_id' => 'phone-a1',
            'camera_tag' => 'A1',
            'file_path' => $a1Path,
            'duration_seconds' => 30,
        ]);

        $this->artisan('rec:rotate-clips', [
            'game_id' => $game->id,
            '--camera' => 'B1',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('câmera B1')
            ->expectsOutputToContain($b1Path)
            ->doesntExpectOutputToContain($a1Path)
            ->assertSuccessful();

        Storage::disk('public')->assertExists($b1Path);
        $this->assertSame('video-b1', Storage::disk('public')->get($b1Path));
    }
}
