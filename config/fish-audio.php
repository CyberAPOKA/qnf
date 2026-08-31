<?php

return [

    'enabled' => (bool) env('FISH_AUDIO_ENABLED', false),

    'api_key' => env('FISH_AUDIO_API_KEY'),

    'model' => env('FISH_AUDIO_MODEL', 's2.1-pro-free'),

    'base_url' => rtrim(env('FISH_AUDIO_BASE_URL', 'https://api.fish.audio'), '/'),

    /*
    | Narrator used after a draft is finalized.
    | Allowed values: lula, bolsonaro, neymar
    | "random" is reserved for a future per-draft/per-team picker and
    | currently falls back to lula.
    */
    'narrator' => env('DRAFT_NARRATOR_VOICE', env('QNF_DRAFT_NARRATOR', 'lula')),

    'voices' => [
        'lula' => env('FISH_AUDIO_VOICE_LULA'),
        'bolsonaro' => env('FISH_AUDIO_VOICE_BOLSONARO'),
        'neymar' => env('FISH_AUDIO_VOICE_NEYMAR'),
    ],

    'disk' => env('FISH_AUDIO_DISK', 'local'),

    'http' => [
        'connect_timeout' => (int) env('FISH_AUDIO_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('FISH_AUDIO_TIMEOUT', 60),
        'retries' => (int) env('FISH_AUDIO_RETRIES', 3),
        'retry_sleep_ms' => (int) env('FISH_AUDIO_RETRY_SLEEP_MS', 1000),
    ],

];
