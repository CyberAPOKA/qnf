# Banco de dados do REC V2

## Tabelas

- `rec_recorder_sessions`: sessão por câmera/jogo, token em hash, lease, heartbeat, buffer, formato e diagnóstico.
- `rec_segments`: sequência idempotente, janela temporal, arquivo, checksum, status, tentativas e retenção.
- `rec_save_requests` (evoluída): SAVE, escopo, janela de captura, contadores, deadline, status e falhas.
- `rec_save_targets`: uma câmera esperada por SAVE; acompanha ack, segmentos e estágios raw/preview/final.
- `rec_clips` (evoluída): liga o clip ao target e guarda paths raw/preview/final, metadados e falhas.
- `rec_save_target_segments`: pivot ordenado entre target e segmentos; permite registrar recortes de sobreposição.
- `rec_operational_events`: trilha operacional com nível, tipo, mensagem, contexto e FKs opcionais.
- `rec_outbox_events`: eventos transacionais pendentes de publicação, tentativas e último erro.

## Relações principais

`game -> recorder_sessions -> segments`; `save_request -> save_targets -> clip`; targets e segmentos têm relação N:N pelo pivot.

## Índices e idempotência

- UUIDs de sessão, segmento e outbox são únicos.
- `rec_segments.idempotency_key` é único.
- `(recorder_session_id, sequence)` é único.
- Um target por `(save_request, camera_tag)` e por `(save_request, recorder_session)`.
- Segmentos têm índices por jogo/janela, status, recebimento e `pinned_until`.

## Operação

Execute `php artisan migrate` antes de habilitar V2. Não faça rollback destrutivo da migration em produção: desligue a flag e preserve banco e vídeos para análise.
