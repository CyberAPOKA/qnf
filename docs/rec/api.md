# API do REC

Todas as rotas sob `games/{game}` (autenticadas):

| Método | Path | Nome |
|--------|------|------|
| GET | `/rec` | `games.rec` |
| POST | `/rec/sessions` | `games.rec.sessions.start` |
| POST | `/rec/sessions/{session}/heartbeat` | `games.rec.sessions.heartbeat` |
| POST | `/rec/sessions/{session}/stop` | `games.rec.sessions.stop` |
| POST | `/rec/sessions/{session}/segments` | `games.rec.sessions.segments` |
| GET | `/rec/sessions/{session}/segments/status` | `games.rec.sessions.segments.status` |
| GET | `/rec/sessions/{session}/save-requests/pending` | `games.rec.sessions.pending-saves` |
| POST | `/rec/sessions/{session}/save-requests/{saveRequest}/ack` | `games.rec.sessions.ack-save` |
| GET | `/rec/sessions/{session}/recovery-requests` | `games.rec.sessions.recovery` |
| POST | `/rec/save-requests` | `games.rec.save-requests.store` |
| GET | `/rec/save-requests/{saveRequest}` | `games.rec.save-requests.show` |

Headers de sessão: `X-REC-Session`, `X-REC-Token`.
