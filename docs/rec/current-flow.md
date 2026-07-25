# Fluxo atual — REC V1

1. A tela usa `useRecBuffer` e `useRecSession`.
2. `POST games/{game}/rec/start` registra `recorder_id` e `camera_tag` no cache.
3. `POST .../heartbeat` mantém o gravador ativo; `stop` o remove.
4. SAVE cria `rec_save_requests`, respeita escopo (`all`, `left`, `right`) e publica `SaveClipRequested`.
5. Cada câmera elegível tira um snapshot do buffer e enfileira o upload no navegador.
6. `POST .../rec/upload` recebe `video` e, opcionalmente, `video_prefix`.
7. O controller grava em `public/rec/{game_id}/{save_uuid}/`.
8. Na própria requisição, `RecClipNormalizeService` concatena prefixo + clip, recorta os últimos segundos e reencoda com FFmpeg.
9. `rec_clips` é criado e `ClipReady` é transmitido.

## Características operacionais

- FFmpeg roda de forma **síncrona** no request de upload; latência e timeout HTTP afetam o usuário.
- Upload duplicado do mesmo `recorder_id` para o SAVE retorna o clip existente.
- O debounce padrão é 800 ms por chave de idempotência.
- Saves consecutivos são permitidos; não existe cooldown global de 10 segundos.
- O estado de gravadores V1 é temporário e não possui as tabelas de sessão/segmentos da V2.

Use este fluxo enquanto `REC_V2_ENABLED=false`.
