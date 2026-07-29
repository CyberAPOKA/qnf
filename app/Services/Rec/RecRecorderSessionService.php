<?php

namespace App\Services\Rec;

use App\Enums\RecRecorderSessionStatus;
use App\Events\RecorderJoined;
use App\Events\RecorderLeft;
use App\Models\Game;
use App\Models\RecRecorderSession;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RecRecorderSessionService
{
    public function __construct(
        private readonly RecRecorderLeaseService $leases,
        private readonly RecTimeSyncService $timeSync,
    ) {}

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $client
     * @return array{session: RecRecorderSession, token: string, config: array<string, int|string>}
     */
    public function start(Game $game, User $user, string $cameraTag, array $capabilities = [], array $client = []): array
    {
        $leaseSeconds = (int) config('rec.recorder_lease_seconds');
        $uuid = (string) Str::uuid();
        $token = Str::random(64);
        $payload = [
            'session_uuid' => $uuid,
            'user_id' => $user->id,
            'camera_tag' => $cameraTag,
        ];

        if (! $this->leases->acquire($game->id, $cameraTag, $payload, $leaseSeconds)) {
            throw new ConflictHttpException(
                'Posição de câmera ocupada: '.$cameraTag,
            );
        }

        $now = now();

        $session = RecRecorderSession::create([
            'uuid' => $uuid,
            'game_id' => $game->id,
            'user_id' => $user->id,
            'camera_tag' => $cameraTag,
            'status' => RecRecorderSessionStatus::Recording,
            'session_token_hash' => Hash::make($token),
            'started_at' => $now,
            'heartbeat_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
            'mime_type' => $capabilities['mime_type'] ?? ($capabilities['mime_types'][0] ?? null),
            'width' => $capabilities['width'] ?? null,
            'height' => $capabilities['height'] ?? null,
            'fps' => $capabilities['fps'] ?? null,
            'has_audio' => $capabilities['has_audio'] ?? null,
            'user_agent' => $client['user_agent'] ?? null,
            'device_fingerprint_hash' => isset($client['device_fingerprint'])
                ? hash('sha256', (string) $client['device_fingerprint'])
                : null,
        ]);

        $recorders = $this->listActiveForGame($game->id);
        rescue(fn () => event(new RecorderJoined($game->id, $recorders)));

        return [
            'session' => $session,
            'token' => $token,
            'config' => $this->clientConfig(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{session: RecRecorderSession, time_sync: array<string, int|float|null>, pending_saves: array<int, mixed>}
     */
    public function heartbeat(RecRecorderSession $session, string $token, array $data = []): array
    {
        $this->assertToken($session, $token);
        $this->assertActive($session);

        $leaseSeconds = (int) config('rec.recorder_lease_seconds');

        if (! $this->leases->renew($session->game_id, $session->camera_tag, $session->uuid, $leaseSeconds)) {
            $session->update([
                'status' => RecRecorderSessionStatus::Expired,
                'stopped_at' => now(),
                'failure_code' => 'lease_lost',
                'failure_message' => 'Lease lost during heartbeat.',
            ]);

            throw new ConflictHttpException('Lease da câmera perdida.');
        }

        $serverReceivedAtMs = (int) floor(microtime(true) * 1000);
        $timeSync = $this->timeSync->sample(
            isset($data['client_sent_at_ms']) ? (int) $data['client_sent_at_ms'] : null,
            $serverReceivedAtMs,
        );

        $updates = [
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addSeconds($leaseSeconds),
            'last_client_event_at' => now(),
            'status' => RecRecorderSessionStatus::Recording,
        ];

        if (array_key_exists('buffer_available_ms', $data)) {
            $updates['buffer_available_ms'] = (int) $data['buffer_available_ms'];
            if ((int) $data['buffer_available_ms'] >= ((int) config('rec.buffer_seconds') * 1000)
                && $session->buffer_ready_at === null) {
                $updates['buffer_ready_at'] = now();
            }
        }

        if (array_key_exists('last_segment_sequence', $data) && $data['last_segment_sequence'] !== null) {
            $updates['last_segment_sequence'] = (int) $data['last_segment_sequence'];
        }

        if ($timeSync['offset_ms'] !== null) {
            $updates['estimated_clock_offset_ms'] = (int) $timeSync['offset_ms'];
        }

        if ($timeSync['rtt_ms'] !== null) {
            $updates['estimated_rtt_ms'] = (int) $timeSync['rtt_ms'];
        }

        $session->update($updates);

        return [
            'session' => $session->fresh(),
            'time_sync' => $timeSync,
            'pending_saves' => [],
        ];
    }

    public function stop(RecRecorderSession $session, string $token): RecRecorderSession
    {
        $this->assertToken($session, $token);

        if ($session->status === RecRecorderSessionStatus::Stopped) {
            return $session;
        }

        $this->leases->release($session->game_id, $session->camera_tag, $session->uuid);

        $session->update([
            'status' => RecRecorderSessionStatus::Stopped,
            'stopped_at' => now(),
            'lease_expires_at' => now(),
        ]);

        $fresh = $session->fresh();
        $recorders = $this->listActiveForGame($session->game_id);
        rescue(fn () => event(new RecorderLeft($session->game_id, $recorders)));

        return $fresh;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveForGame(int $gameId): array
    {
        return RecRecorderSession::query()
            ->with('user:id,name')
            ->where('game_id', $gameId)
            ->whereIn('status', [
                RecRecorderSessionStatus::Starting->value,
                RecRecorderSessionStatus::Recording->value,
                RecRecorderSessionStatus::Degraded->value,
                RecRecorderSessionStatus::Reconnecting->value,
            ])
            ->where(function ($query) {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '>=', now());
            })
            ->orderBy('camera_tag')
            ->get()
            ->map(fn (RecRecorderSession $session) => [
                'uuid' => $session->uuid,
                'recorder_id' => $session->uuid,
                'camera_tag' => $session->camera_tag,
                'user_id' => $session->user_id,
                'user_name' => $session->user?->name,
                'status' => $session->status->value,
                'buffer_available_ms' => $session->buffer_available_ms,
            ])
            ->values()
            ->all();
    }

    public function assertToken(RecRecorderSession $session, string $token): void
    {
        if ($token === '' || ! Hash::check($token, $session->session_token_hash)) {
            throw ValidationException::withMessages([
                'token' => ['Token de sessão inválido.'],
            ]);
        }
    }

    public function assertActive(RecRecorderSession $session): void
    {
        if (! $session->status->isActive()) {
            throw new HttpException(410, 'Sessão de gravação inativa.');
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function clientConfig(): array
    {
        return [
            'segment_seconds' => (int) config('rec.segment_seconds'),
            'buffer_seconds' => (int) config('rec.buffer_seconds'),
            'retention_seconds' => (int) config('rec.server_retention_seconds'),
            'heartbeat_seconds' => (int) config('rec.heartbeat_seconds'),
            'post_roll_seconds' => (int) config('rec.post_roll_seconds'),
            'pending_save_poll_seconds' => (int) config('rec.pending_save_poll_seconds'),
            'upload_max_concurrency' => (int) config('rec.upload_max_concurrency'),
        ];
    }

    public function expireStaleSessions(): int
    {
        $sessions = RecRecorderSession::query()
            ->whereIn('status', [
                RecRecorderSessionStatus::Starting->value,
                RecRecorderSessionStatus::Recording->value,
                RecRecorderSessionStatus::Degraded->value,
                RecRecorderSessionStatus::Reconnecting->value,
            ])
            ->where('lease_expires_at', '<', now())
            ->get();

        $count = 0;

        foreach ($sessions as $session) {
            $this->leases->release($session->game_id, $session->camera_tag, $session->uuid);
            $session->update([
                'status' => RecRecorderSessionStatus::Expired,
                'stopped_at' => now(),
                'failure_code' => 'lease_expired',
                'failure_message' => 'Recorder lease expired.',
            ]);
            $count++;
        }

        return $count;
    }
}
