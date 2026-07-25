<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\RecSaveRequest;
use App\Models\User;
use App\Services\RecSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RecSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_left_save_targets_only_a1_and_b1_and_allows_consecutive_saves(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $game = $this->createGame();
        $session = app(RecSessionService::class);

        $session->registerRecorder($game, $user, 'rec-a1', 'A1');
        $session->registerRecorder($game, $user, 'rec-a2', 'A2');
        $session->registerRecorder($game, $user, 'rec-b1', 'B1');
        $session->registerRecorder($game, $user, 'rec-b2', 'B2');

        $this->actingAs($user)
            ->postJson(route('games.rec.save', $game), [
                'capture_scope' => 'left',
            ])
            ->assertOk()
            ->assertJsonPath('expected_recorders', 2)
            ->assertJsonPath('save_request.capture_scope', 'left')
            ->assertJsonPath('save_request.camera_tags', ['A1', 'B1'])
            ->assertJsonPath('cooldown_seconds', 0);

        $this->assertDatabaseHas('rec_save_requests', [
            'game_id' => $game->id,
            'capture_scope' => 'left',
        ]);

        $this->actingAs($otherUser)
            ->postJson(route('games.rec.save', $game), [
                'capture_scope' => 'right',
            ])
            ->assertOk()
            ->assertJsonPath('expected_recorders', 2)
            ->assertJsonPath('save_request.capture_scope', 'right');

        $this->assertSame(2, RecSaveRequest::count());
    }

    public function test_upload_rejects_camera_outside_save_scope(): void
    {
        $user = User::factory()->create();
        $game = $this->createGame();
        $saveRequest = app(RecSessionService::class)
            ->createSaveRequest($game, $user, 'left');

        $this->actingAs($user)
            ->post(route('games.rec.upload', $game), [
                'save_request_uuid' => $saveRequest->uuid,
                'recorder_id' => 'rec-a2',
                'camera_tag' => 'A2',
                'duration_seconds' => 30,
                'video' => UploadedFile::fake()->create('clip.webm', 100, 'video/webm'),
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Esta câmera não pertence ao lado selecionado para o SAVE.',
            );
    }

    private function createGame(): Game
    {
        return Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::OPEN,
        ]);
    }
}
