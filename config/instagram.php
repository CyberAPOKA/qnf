<?php

return [

    'enabled' => (bool) env('INSTAGRAM_ENABLED', false),

    'dry_run' => (bool) env('INSTAGRAM_DRY_RUN', false),

    'app_id' => env('INSTAGRAM_APP_ID'),

    'app_secret' => env('INSTAGRAM_APP_SECRET'),

    'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),

    'user_id' => env('INSTAGRAM_USER_ID'),

    'graph_version' => env('INSTAGRAM_GRAPH_VERSION', 'v22.0'),

    'graph_base_url' => rtrim(env('INSTAGRAM_GRAPH_BASE_URL', 'https://graph.instagram.com'), '/'),

    'default_story_audio_path' => env('INSTAGRAM_DEFAULT_STORY_AUDIO_PATH'),

    'own_username' => env('INSTAGRAM_OWN_USERNAME', 'qnfporto'),

    'queue' => env('INSTAGRAM_QUEUE', 'default'),

    'story_duration_seconds' => (int) env('INSTAGRAM_STORY_DURATION_SECONDS', 15),

    'caption_hashtags' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INSTAGRAM_CAPTION_HASHTAGS', '#qnf #qnfporto #futebol'))
    ))),

    'asset_retention_hours' => (int) env('INSTAGRAM_ASSET_RETENTION_HOURS', 48),

    'failed_asset_retention_days' => (int) env('INSTAGRAM_FAILED_ASSET_RETENTION_DAYS', 14),

    'token_refresh_days_before' => (int) env('INSTAGRAM_TOKEN_REFRESH_DAYS_BEFORE', 7),

    'http' => [
        'connect_timeout' => (int) env('INSTAGRAM_HTTP_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('INSTAGRAM_HTTP_TIMEOUT', 60),
        'retries' => (int) env('INSTAGRAM_HTTP_RETRIES', 2),
        'user_agent' => env('INSTAGRAM_USER_AGENT', 'QNF-Instagram/1.0'),
    ],

    'limits' => [
        'carousel_items' => 10,
        'user_tags' => 20,
        'caption_length' => 2200,
        'hashtags' => 30,
        'image_max_bytes' => 8 * 1024 * 1024,
        'video_max_bytes' => 100 * 1024 * 1024,
        'video_min_seconds' => 3,
        'video_max_seconds' => 60,
        'publishing_warning_remaining' => 5,
    ],

    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'timeout' => (int) env('INSTAGRAM_FFMPEG_TIMEOUT', 180),
    ],

];
