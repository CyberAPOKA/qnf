<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\RecRecorderSessionStatus;
use App\Models\Game;
use App\Models\RecRecorderSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_camera_exclusivity_returns_409(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $game = $this->createGame();

        $this->actingAs($userA)
            ->postJson(route('games.rec.sessions.start', $game), [
                'camera_tag' => 'A1',
            ])
            ->assertCreated()
            ->assertJsonPath('session.camera_tag', 'A1')
            ->assertJsonStructure(['session' => ['uuid', 'token']]);

        $this->actingAs($userB)
            ->postJson(route('games.rec.sessions.start', $game), [
                'camera_tag' => 'A1',
            ])
            ->assertStatus(409)
            ->assertJsonPath('camera_tag', 'A1');

        $this->assertSame(1, RecRecorderSession::query()->where('camera_tag', 'A1')->where('status', RecRecorderSessionStatus::Recording)->count());
    }

    public function test_duplicate_segment_upload_is_idempotent(): void
    {
        $user = User::factory()->create();
        $game = $this->createGame();

        $start = $this->actingAs($user)
            ->postJson(route('games.rec.sessions.start', $game), [
                'camera_tag' => 'B1',
            ])
            ->assertCreated();

        $uuid = $start->json('session.uuid');
        $token = $start->json('session.token');
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'sequence' => 1,
            'idempotency_key' => $idempotencyKey,
            'duration_ms' => 5000,
            'estimated_server_started_at' => now()->subSeconds(5)->toIso8601String(),
            'estimated_server_ended_at' => now()->toIso8601String(),
        ];

        $first = $this->actingAs($user)
            ->withHeaders(['X-REC-Token' => $token])
            ->postJson(route('games.rec.sessions.segments', [$game, $uuid]), $payload)
            ->assertCreated();

        $second = $this->actingAs($user)
            ->withHeaders(['X-REC-Token' => $token])
            ->postJson(route('games.rec.sessions.segments', [$game, $uuid]), $payload)
            ->assertCreated();

        $this->assertSame($first->json('segment.uuid'), $second->json('segment.uuid'));
        $this->assertDatabaseCount('rec_segments', 1);
    }

    public function test_heartbeat_requires_valid_token(): void
    {
        $user = User::factory()->create();
        $game = $this->createGame();
        $session = RecRecorderSession::create([
            'uuid' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $user->id,
            'camera_tag' => 'A2',
            'status' => RecRecorderSessionStatus::Recording,
            'session_token_hash' => Hash::make('secret-token'),
            'started_at' => now(),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addSeconds(35),
        ]);

        $this->actingAs($user)
            ->withHeaders(['X-REC-Token' => 'wrong'])
            ->postJson(route('games.rec.sessions.heartbeat', [$game, $session->uuid]), [])
            ->assertStatus(422);
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
