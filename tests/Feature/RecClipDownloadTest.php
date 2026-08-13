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

class RecClipDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_download_a_clip(): void
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

        $path = "rec/{$game->id}/{$save->uuid}/clip.webm";
        Storage::disk('public')->put($path, 'fake-webm');

        $clip = RecClip::create([
            'rec_save_request_id' => $save->id,
            'game_id' => $game->id,
            'user_id' => $user->id,
            'recorder_id' => 'phone-b1',
            'camera_tag' => 'B1',
            'file_path' => $path,
            'duration_seconds' => 30,
        ]);

        $mp4 = "rec/converted/{$clip->id}.mp4";
        Storage::disk('public')->put($mp4, 'fake-mp4');

        $this->actingAs($user)
            ->get(route('rec.clips.download', $clip))
            ->assertOk()
            ->assertDownload('rec-B1-'.$clip->created_at->format('His').'.mp4')
            ->assertHeader('content-type', 'video/mp4');
    }

    public function test_download_falls_back_to_webm_when_mp4_is_unavailable(): void
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

        $path = "rec/{$game->id}/{$save->uuid}/clip.webm";
        Storage::disk('public')->put($path, 'fake-webm');

        $clip = RecClip::create([
            'rec_save_request_id' => $save->id,
            'game_id' => $game->id,
            'user_id' => $user->id,
            'recorder_id' => 'phone-b1',
            'camera_tag' => 'B1',
            'file_path' => $path,
            'duration_seconds' => 30,
        ]);

        $this->actingAs($user)
            ->get(route('rec.clips.download', $clip))
            ->assertOk()
            ->assertDownload('rec-B1-'.$clip->created_at->format('His').'.webm');
    }
}
