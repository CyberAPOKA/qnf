# API do REC

Todas as rotas abaixo ficam sob `games/{game}`, usam sessão autenticada Laravel e têm prefixo de nome `games.rec`.

## V1

- `GET /rec` — `games.rec`
- `POST /rec/start` — `games.rec.start`
- `POST /rec/heartbeat` — `games.rec.heartbeat`
- `POST /rec/stop` — `games.rec.stop`
- `POST /rec/save` — `games.rec.save`
- `POST /rec/upload` — `games.rec.upload`

## V2

- `POST /rec/sessions` — `games.rec.sessions.start`
- `POST /rec/sessions/{session}/heartbeat` — `games.rec.sessions.heartbeat`
- `POST /rec/sessions/{session}/stop` — `games.rec.sessions.stop`
- `POST /rec/sessions/{session}/segments` — `games.rec.sessions.segments`
- `GET /rec/sessions/{session}/segments/status` — `games.rec.sessions.segments.status`
- `GET /rec/sessions/{session}/save-requests/pending` — `games.rec.sessions.pending-saves`
- `POST /rec/sessions/{session}/save-requests/{saveRequest}/ack` — `games.rec.sessions.ack-save`
- `GET /rec/sessions/{session}/recovery-requests` — `games.rec.sessions.recovery`
- `POST /rec/save-requests` — `games.rec.save-requests.store`
- `GET /rec/save-requests/{saveRequest}` — `games.rec.save-requests.show`

## Autenticação de sessão V2

Após iniciar, envie `X-REC-Token` em heartbeat, stop, segmentos, ack e consultas da sessão. O UUID da sessão está na URL; `X-REC-Session` também é enviado pelo frontend. Segmentos usam `Idempotency-Key` e o SAVE aceita `idempotency_key`.
