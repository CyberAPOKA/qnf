<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\RecRecorderSessionStatus;
use App\Enums\RecSegmentStatus;
use App\Models\Game;
use App\Models\RecRecorderSession;
use App\Models\RecSaveRequest;
use App\Models\RecSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecV2SaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_left_and_right_scope_create_matching_targets(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $game = $this->createGame();

        $this->openRecSession($user, $game, 'A1');
        $this->openRecSession($user, $game, 'A2');
        $this->openRecSession($user, $game, 'B1');
        $this->openRecSession($user, $game, 'B2');

        $left = $this->actingAs($user)
            ->postJson(route('games.rec.save-requests.store', $game), [
                'capture_scope' => 'left',
                'idempotency_key' => 'left-1',
            ])
            ->assertCreated();

        $this->assertSame(['A1', 'B1'], collect($left->json('save_request.targets'))->pluck('camera_tag')->sort()->values()->all());

        $right = $this->actingAs($user)
            ->postJson(route('games.rec.save-requests.store', $game), [
                'capture_scope' => 'right',
                'idempotency_key' => 'right-1',
            ])
            ->assertCreated();

        $this->assertSame(['A2', 'B2'], collect($right->json('save_request.targets'))->pluck('camera_tag')->sort()->values()->all());
    }

    public function test_consecutive_saves_are_allowed(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $game = $this->createGame();
        $this->openRecSession($user, $game, 'A1');

        $this->actingAs($user)
            ->postJson(route('games.rec.save-requests.store', $game), [
                'capture_scope' => 'all',
                'idempotency_key' => 'save-1',
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson(route('games.rec.save-requests.store', $game), [
                'capture_scope' => 'all',
                'idempotency_key' => 'save-2',
            ])
            ->assertCreated();

        $this->assertSame(2, RecSaveRequest::count());
    }

    public function test_upload_outside_scope_is_rejected_on_legacy_path(): void
    {
        $user = User::factory()->create();
        $game = $this->createGame();
        $saveRequest = RecSaveRequest::create([
            'game_id' => $game->id,
            'triggered_by' => $user->id,
            'uuid' => (string) Str::uuid(),
            'capture_scope' => 'left',
        ]);

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

    public function test_v2_save_only_targets_active_cameras_in_scope(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $game = $this->createGame();
        $this->openRecSession($user, $game, 'A1');
        $this->openRecSession($user, $game, 'A2');

        $response = $this->actingAs($user)
            ->postJson(route('games.rec.save-requests.store', $game), [
                'capture_scope' => 'left',
                'idempotency_key' => 'left-only-a1',
            ])
            ->assertCreated();

        $tags = collect($response->json('save_request.targets'))->pluck('camera_tag')->all();

        $this->assertSame(['A1'], $tags);
        $this->assertSame(1, $response->json('save_request.expected_count'));
    }

    public function test_segment_pinning_for_save_window(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $game = $this->createGame();
        $session = $this->createSessionModel($user, $game, 'A1');

        RecSegment::create([
            'uuid' => (string) Str::uuid(),
            'recorder_session_id' => $session->id,
            'game_id' => $game->id,
            'sequence' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'estimated_server_started_at' => now()->subSeconds(20),
            'estimated_server_ended_at' => now()->subSeconds(15),
            'status' => RecSegmentStatus::Verified,
            'file_path' => 'rec/test.webm',
            'storage_disk' => 'public',
            'received_at' => now()->subSeconds(15),
            'verified_at' => now()->subSeconds(15),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('games.rec.save-requests.store', $game), [
                'capture_scope' => 'all',
                'idempotency_key' => 'pin-1',
            ])
            ->assertCreated();

        $this->assertGreaterThanOrEqual(1, $response->json('save_request.targets.0.segments_received'));
        $this->assertDatabaseHas('rec_segments', [
            'recorder_session_id' => $session->id,
            'status' => RecSegmentStatus::Pinned->value,
        ]);
    }

    private function openRecSession(User $user, Game $game, string $cameraTag): array
    {
        $response = $this->actingAs($user)
            ->postJson(route('games.rec.sessions.start', $game), [
                'camera_tag' => $cameraTag,
            ])
            ->assertCreated();

        return [
            'uuid' => $response->json('session.uuid'),
            'token' => $response->json('session.token'),
        ];
    }

    private function createSessionModel(User $user, Game $game, string $cameraTag): RecRecorderSession
    {
        $uuid = (string) Str::uuid();

        app(\App\Services\Rec\RecRecorderLeaseService::class)->acquire(
            $game->id,
            $cameraTag,
            [
                'session_uuid' => $uuid,
                'user_id' => $user->id,
                'camera_tag' => $cameraTag,
            ],
            35,
        );

        return RecRecorderSession::create([
            'uuid' => $uuid,
            'game_id' => $game->id,
            'user_id' => $user->id,
            'camera_tag' => $cameraTag,
            'status' => RecRecorderSessionStatus::Recording,
            'session_token_hash' => Hash::make('token'),
            'started_at' => now(),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addSeconds(35),
        ]);
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
