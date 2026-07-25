# REC V2 frontend route contract

The V2 frontend expects the following Ziggy/Laravel route names. The backend
route implementation is delivered separately:

- `games.rec.sessions.start`
- `games.rec.sessions.heartbeat`
- `games.rec.sessions.stop`
- `games.rec.sessions.segments`
- `games.rec.sessions.segments.status`
- `games.rec.sessions.pending-saves`
- `games.rec.sessions.ack-save`
- `games.rec.sessions.recovery`
- `games.rec.save`
- `games.rec.save-requests.show`

Session-bound requests send `X-REC-Session` and `X-REC-Token`. Segment uploads
also send `Idempotency-Key` and multipart segment metadata.
