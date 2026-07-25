# Layout de storage

Discos padrão:

- `REC_STORAGE_DISK=public`: segmentos e artefatos.
- `REC_TEMP_STORAGE_DISK=local`: reservado para temporários.

## V1

```text
rec/{game_id}/{save_uuid}/{arquivo}.webm
```

## V2

```text
rec/{app_env}/games/{game_id}/sessions/{session_uuid}/segments/{sequence}-{segment_uuid}.webm
rec/{app_env}/games/{game_id}/saves/{save_uuid}/{camera_tag}/raw/{arquivo}
rec/{app_env}/games/{game_id}/saves/{save_uuid}/{camera_tag}/preview/preview.webm
rec/{app_env}/games/{game_id}/saves/{save_uuid}/{camera_tag}/final/final.webm
rec/{app_env}/games/{game_id}/tmp/{arquivo}
```

Com disco `public`, garanta `php artisan storage:link` e permissão de escrita para PHP e worker. O disco configurado precisa oferecer path local para o FFmpeg atual (`Storage::path`); storage remoto exige adaptação/staging local.

Segmentos não fixados expiram após `REC_SERVER_RETENTION_SECONDS` (120 s). Segmentos usados por SAVE recebem `pinned_until`; raw tem retenção configurável por `REC_RAW_RETENTION_DAYS`.
