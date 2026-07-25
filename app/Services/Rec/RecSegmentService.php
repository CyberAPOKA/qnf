<?php

namespace App\Services\Rec;

use App\Enums\RecSegmentStatus;
use App\Models\RecRecorderSession;
use App\Models\RecSegment;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecSegmentService
{
    public function __construct(
        private readonly RecStorageService $storage,
        private readonly RecRecorderSessionService $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function announceAndStore(
        RecRecorderSession $session,
        string $token,
        array $meta,
        ?UploadedFile $file = null,
    ): RecSegment {
        $this->sessions->assertToken($session, $token);
        $this->sessions->assertActive($session);

        $idempotencyKey = (string) $meta['idempotency_key'];
        $sequence = (int) $meta['sequence'];

        $existing = RecSegment::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $existingBySequence = RecSegment::query()
            ->where('recorder_session_id', $session->id)
            ->where('sequence', $sequence)
            ->first();

        if ($existingBySequence) {
            return $existingBySequence;
        }

        return DB::transaction(function () use ($session, $meta, $file, $idempotencyKey, $sequence) {
            $uuid = (string) ($meta['uuid'] ?? Str::uuid());
            $disk = $this->storage->disk();
            $path = null;
            $bytes = null;
            $checksum = $meta['checksum_sha256'] ?? null;
            $status = RecSegmentStatus::Announced;

            if ($file !== null) {
                $computed = hash_file('sha256', $file->getRealPath());

                if ($checksum && ! hash_equals((string) $checksum, $computed)) {
                    throw ValidationException::withMessages([
                        'checksum_sha256' => ['Checksum do segmento inválido.'],
                    ]);
                }

                $checksum = $computed;
                $ext = $file->getClientOriginalExtension() ?: 'webm';
                $path = $this->storage->segmentPath(
                    $session->game_id,
                    $session->uuid,
                    $sequence,
                    $uuid,
                    $ext,
                );
                Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));
                $bytes = Storage::disk($disk)->size($path);
                $status = RecSegmentStatus::Verified;
            }

            try {
                $segment = RecSegment::create([
                    'uuid' => $uuid,
                    'recorder_session_id' => $session->id,
                    'game_id' => $session->game_id,
                    'sequence' => $sequence,
                    'idempotency_key' => $idempotencyKey,
                    'client_started_at' => $this->toDateTime($meta['client_started_at'] ?? null),
                    'client_ended_at' => $this->toDateTime($meta['client_ended_at'] ?? null),
                    'estimated_server_started_at' => $this->toDateTime($meta['estimated_server_started_at'] ?? null),
                    'estimated_server_ended_at' => $this->toDateTime($meta['estimated_server_ended_at'] ?? null),
                    'duration_ms' => isset($meta['duration_ms']) ? (int) $meta['duration_ms'] : null,
                    'file_path' => $path,
                    'storage_disk' => $path ? $disk : null,
                    'mime_type' => $meta['mime_type'] ?? $file?->getMimeType(),
                    'container' => $meta['container'] ?? null,
                    'video_codec' => $meta['video_codec'] ?? null,
                    'audio_codec' => $meta['audio_codec'] ?? null,
                    'bytes' => $bytes,
                    'checksum_sha256' => $checksum,
                    'status' => $status,
                    'upload_attempts' => $file ? 1 : 0,
                    'received_at' => $file ? now() : null,
                    'verified_at' => $status === RecSegmentStatus::Verified ? now() : null,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return RecSegment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->orWhere(function ($query) use ($session, $sequence) {
                        $query->where('recorder_session_id', $session->id)
                            ->where('sequence', $sequence);
                    })
                    ->firstOrFail();
            }

            $session->update([
                'last_segment_sequence' => max((int) $session->last_segment_sequence, $sequence),
                'last_segment_received_at' => $file ? now() : $session->last_segment_received_at,
            ]);

            return $segment;
        });
    }

    public function pin(RecSegment $segment, CarbonInterface $until): RecSegment
    {
        $segment->update([
            'status' => RecSegmentStatus::Pinned,
            'pinned_until' => $until->greaterThan($segment->pinned_until ?? now())
                ? $until
                : $segment->pinned_until,
        ]);

        return $segment->fresh();
    }

    public function expireUnpinned(): int
    {
        $retention = (int) config('rec.server_retention_seconds');
        $cutoff = now()->subSeconds($retention);

        $segments = RecSegment::query()
            ->whereNotIn('status', [
                RecSegmentStatus::Pinned->value,
                RecSegmentStatus::Expired->value,
            ])
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('pinned_until')
                    ->where('received_at', '<', $cutoff);
            })
            ->limit(200)
            ->get();

        $count = 0;

        foreach ($segments as $segment) {
            if ($segment->file_path && $segment->storage_disk) {
                Storage::disk($segment->storage_disk)->delete($segment->file_path);
            }

            $segment->update([
                'status' => RecSegmentStatus::Expired,
                'file_path' => null,
            ]);
            $count++;
        }

        return $count;
    }

    private function toDateTime(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($value);
    }
}
