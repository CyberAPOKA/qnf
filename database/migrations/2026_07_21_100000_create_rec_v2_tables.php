<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_recorder_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('camera_tag', 8);
            $table->string('status', 32);
            $table->string('session_token_hash');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('buffer_ready_at')->nullable();
            $table->unsignedInteger('buffer_available_ms')->nullable();
            $table->unsignedInteger('last_segment_sequence')->nullable();
            $table->timestamp('last_segment_received_at')->nullable();
            $table->timestamp('last_client_event_at')->nullable();
            $table->integer('estimated_clock_offset_ms')->nullable();
            $table->unsignedInteger('estimated_rtt_ms')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('container', 32)->nullable();
            $table->string('video_codec', 64)->nullable();
            $table->string('audio_codec', 64)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('fps')->nullable();
            $table->boolean('has_audio')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_fingerprint_hash')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table->index('game_id');
            $table->index(['game_id', 'camera_tag']);
            $table->index(['game_id', 'status']);
            $table->index('lease_expires_at');
            $table->index('user_id');
        });

        Schema::create('rec_segments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('recorder_session_id')->constrained('rec_recorder_sessions')->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('idempotency_key')->unique();
            $table->timestamp('client_started_at')->nullable();
            $table->timestamp('client_ended_at')->nullable();
            $table->timestamp('estimated_server_started_at')->nullable();
            $table->timestamp('estimated_server_ended_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('file_path')->nullable();
            $table->string('storage_disk', 64)->nullable();
            $table->string('mime_type')->nullable();
            $table->string('container', 32)->nullable();
            $table->string('video_codec', 64)->nullable();
            $table->string('audio_codec', 64)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('status', 32);
            $table->unsignedSmallInteger('upload_attempts')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('pinned_until')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['recorder_session_id', 'sequence'], 'rec_segments_session_sequence_unique');
            $table->index(['game_id', 'estimated_server_started_at', 'estimated_server_ended_at'], 'rec_segments_game_time_index');
            $table->index('status', 'rec_segments_status_index');
            $table->index('pinned_until', 'rec_segments_pinned_index');
            $table->index(['status', 'received_at'], 'rec_segments_status_received_index');
        });

        Schema::table('rec_save_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('rec_save_requests', 'status')) {
                $table->string('status', 32)->default('requested')->after('capture_scope');
            }
            if (! Schema::hasColumn('rec_save_requests', 'triggered_at')) {
                $table->timestamp('triggered_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('rec_save_requests', 'capture_from')) {
                $table->timestamp('capture_from')->nullable()->after('triggered_at');
            }
            if (! Schema::hasColumn('rec_save_requests', 'capture_until')) {
                $table->timestamp('capture_until')->nullable()->after('capture_from');
            }
            if (! Schema::hasColumn('rec_save_requests', 'expected_count')) {
                $table->unsignedSmallInteger('expected_count')->default(0)->after('capture_until');
            }
            if (! Schema::hasColumn('rec_save_requests', 'acknowledged_count')) {
                $table->unsignedSmallInteger('acknowledged_count')->default(0)->after('expected_count');
            }
            if (! Schema::hasColumn('rec_save_requests', 'received_count')) {
                $table->unsignedSmallInteger('received_count')->default(0)->after('acknowledged_count');
            }
            if (! Schema::hasColumn('rec_save_requests', 'ready_count')) {
                $table->unsignedSmallInteger('ready_count')->default(0)->after('received_count');
            }
            if (! Schema::hasColumn('rec_save_requests', 'failed_count')) {
                $table->unsignedSmallInteger('failed_count')->default(0)->after('ready_count');
            }
            if (! Schema::hasColumn('rec_save_requests', 'deadline_at')) {
                $table->timestamp('deadline_at')->nullable()->after('failed_count');
            }
            if (! Schema::hasColumn('rec_save_requests', 'processing_started_at')) {
                $table->timestamp('processing_started_at')->nullable()->after('deadline_at');
            }
            if (! Schema::hasColumn('rec_save_requests', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('processing_started_at');
            }
            if (! Schema::hasColumn('rec_save_requests', 'failure_code')) {
                $table->string('failure_code', 64)->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('rec_save_requests', 'failure_message')) {
                $table->text('failure_message')->nullable()->after('failure_code');
            }
        });

        Schema::create('rec_save_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rec_save_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorder_session_id')->nullable()->constrained('rec_recorder_sessions')->nullOnDelete();
            $table->string('camera_tag', 8);
            $table->string('status', 32);
            $table->timestamp('expected_from')->nullable();
            $table->timestamp('expected_until')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedSmallInteger('segments_expected')->default(0);
            $table->unsignedSmallInteger('segments_received')->default(0);
            $table->unsignedSmallInteger('segments_missing')->default(0);
            $table->timestamp('raw_ready_at')->nullable();
            $table->timestamp('preview_ready_at')->nullable();
            $table->timestamp('final_ready_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['rec_save_request_id', 'camera_tag']);
            $table->unique(['rec_save_request_id', 'recorder_session_id'], 'rec_save_targets_request_session_unique');
        });

        Schema::table('rec_clips', function (Blueprint $table) {
            if (! Schema::hasColumn('rec_clips', 'rec_save_target_id')) {
                $table->foreignId('rec_save_target_id')->nullable()->after('rec_save_request_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('rec_clips', 'raw_file_path')) {
                $table->string('raw_file_path')->nullable()->after('file_path');
            }
            if (! Schema::hasColumn('rec_clips', 'preview_file_path')) {
                $table->string('preview_file_path')->nullable()->after('raw_file_path');
            }
            if (! Schema::hasColumn('rec_clips', 'final_file_path')) {
                $table->string('final_file_path')->nullable()->after('preview_file_path');
            }
            if (! Schema::hasColumn('rec_clips', 'storage_disk')) {
                $table->string('storage_disk', 64)->nullable()->after('final_file_path');
            }
            if (! Schema::hasColumn('rec_clips', 'status')) {
                $table->string('status', 32)->nullable()->after('storage_disk');
            }
            if (! Schema::hasColumn('rec_clips', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable()->after('duration_seconds');
            }
            if (! Schema::hasColumn('rec_clips', 'bytes')) {
                $table->unsignedBigInteger('bytes')->nullable()->after('duration_ms');
            }
            if (! Schema::hasColumn('rec_clips', 'checksum_sha256')) {
                $table->string('checksum_sha256', 64)->nullable()->after('bytes');
            }
            if (! Schema::hasColumn('rec_clips', 'mime_type')) {
                $table->string('mime_type')->nullable()->after('checksum_sha256');
            }
            if (! Schema::hasColumn('rec_clips', 'container')) {
                $table->string('container', 32)->nullable()->after('mime_type');
            }
            if (! Schema::hasColumn('rec_clips', 'video_codec')) {
                $table->string('video_codec', 64)->nullable()->after('container');
            }
            if (! Schema::hasColumn('rec_clips', 'audio_codec')) {
                $table->string('audio_codec', 64)->nullable()->after('video_codec');
            }
            if (! Schema::hasColumn('rec_clips', 'processing_attempts')) {
                $table->unsignedSmallInteger('processing_attempts')->default(0)->after('audio_codec');
            }
            if (! Schema::hasColumn('rec_clips', 'processing_started_at')) {
                $table->timestamp('processing_started_at')->nullable()->after('processing_attempts');
            }
            if (! Schema::hasColumn('rec_clips', 'processing_finished_at')) {
                $table->timestamp('processing_finished_at')->nullable()->after('processing_started_at');
            }
            if (! Schema::hasColumn('rec_clips', 'failure_code')) {
                $table->string('failure_code', 64)->nullable()->after('processing_finished_at');
            }
            if (! Schema::hasColumn('rec_clips', 'failure_message')) {
                $table->text('failure_message')->nullable()->after('failure_code');
            }
        });

        Schema::create('rec_save_target_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rec_save_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rec_segment_id')->constrained('rec_segments')->cascadeOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->unsignedInteger('overlap_from_ms')->nullable();
            $table->unsignedInteger('overlap_until_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['rec_save_target_id', 'rec_segment_id'], 'rec_save_target_segments_unique');
        });

        Schema::create('rec_operational_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorder_session_id')->nullable()->constrained('rec_recorder_sessions')->nullOnDelete();
            $table->foreignId('rec_save_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rec_save_target_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rec_segment_id')->nullable()->constrained('rec_segments')->nullOnDelete();
            $table->string('level', 16)->default('info');
            $table->string('event_type', 64);
            $table->text('message')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['game_id', 'occurred_at']);
            $table->index('event_type');
        });

        Schema::create('rec_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 64);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_outbox_events');
        Schema::dropIfExists('rec_operational_events');
        Schema::dropIfExists('rec_save_target_segments');

        Schema::table('rec_clips', function (Blueprint $table) {
            $columns = [
                'rec_save_target_id',
                'raw_file_path',
                'preview_file_path',
                'final_file_path',
                'storage_disk',
                'status',
                'duration_ms',
                'bytes',
                'checksum_sha256',
                'mime_type',
                'container',
                'video_codec',
                'audio_codec',
                'processing_attempts',
                'processing_started_at',
                'processing_finished_at',
                'failure_code',
                'failure_message',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('rec_clips', $column)) {
                    if ($column === 'rec_save_target_id') {
                        $table->dropConstrainedForeignId('rec_save_target_id');
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::dropIfExists('rec_save_targets');

        Schema::table('rec_save_requests', function (Blueprint $table) {
            $columns = [
                'status',
                'triggered_at',
                'capture_from',
                'capture_until',
                'expected_count',
                'acknowledged_count',
                'received_count',
                'ready_count',
                'failed_count',
                'deadline_at',
                'processing_started_at',
                'completed_at',
                'failure_code',
                'failure_message',
            ];

            $existing = array_values(array_filter(
                $columns,
                fn (string $column) => Schema::hasColumn('rec_save_requests', $column),
            ));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        Schema::dropIfExists('rec_segments');
        Schema::dropIfExists('rec_recorder_sessions');
    }
};
