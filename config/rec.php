<?php

return [

    'segment_seconds' => (int) env('REC_SEGMENT_SECONDS', 5),

    'buffer_seconds' => (int) env('REC_BUFFER_SECONDS', 30),

    'server_retention_seconds' => (int) env('REC_SERVER_RETENTION_SECONDS', 120),

    'local_retention_seconds' => (int) env('REC_LOCAL_RETENTION_SECONDS', 180),

    'post_roll_seconds' => (int) env('REC_POST_ROLL_SECONDS', 2),

    'heartbeat_seconds' => (int) env('REC_HEARTBEAT_SECONDS', 10),

    'recorder_lease_seconds' => (int) env('REC_RECORDER_LEASE_SECONDS', 35),

    'save_debounce_milliseconds' => (int) env('REC_SAVE_DEBOUNCE_MILLISECONDS', 800),

    /** Per-side SAVE lock so left/right don't block each other, but both block "all". */
    'save_scope_cooldown_seconds' => (int) env('REC_SAVE_SCOPE_COOLDOWN_SECONDS', 10),

    'pending_save_poll_seconds' => (int) env('REC_PENDING_SAVE_POLL_SECONDS', 2),

    'upload_max_concurrency' => (int) env('REC_UPLOAD_MAX_CONCURRENCY', 1),

    'upload_request_timeout_seconds' => (int) env('REC_UPLOAD_REQUEST_TIMEOUT_SECONDS', 120),

    'processing_queue' => env('REC_PROCESSING_QUEUE', 'rec-video-processing'),

    'storage_disk' => env('REC_STORAGE_DISK', 'public'),

    'temp_storage_disk' => env('REC_TEMP_STORAGE_DISK', 'local'),

    'preview_height' => (int) env('REC_PREVIEW_HEIGHT', 480),

    'preview_video_bitrate' => env('REC_PREVIEW_VIDEO_BITRATE', '700k'),

    'final_video_bitrate' => env('REC_FINAL_VIDEO_BITRATE', '1600k'),

    'audio_bitrate' => env('REC_AUDIO_BITRATE', '96k'),

    'raw_retention_days' => (int) env('REC_RAW_RETENTION_DAYS', 7),

    'failed_retention_days' => (int) env('REC_FAILED_RETENTION_DAYS', 30),

    'metrics_enabled' => (bool) env('REC_METRICS_ENABLED', true),

    'camera_tags' => ['A1', 'A2', 'B1', 'B2'],

    'scope_camera_tags' => [
        'all' => ['A1', 'A2', 'B1', 'B2'],
        'left' => ['A1', 'B1'],
        'right' => ['A2', 'B2'],
    ],

];
