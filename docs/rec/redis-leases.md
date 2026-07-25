# Leases de câmera

O lease impede duas sessões de ocuparem o mesmo `camera_tag` no mesmo jogo.

Chave:

```text
rec:game:{game_id}:camera:{camera_tag}
```

O valor contém `session_uuid`, `user_id` e `camera_tag`; o TTL padrão é `REC_RECORDER_LEASE_SECONDS=35`. Heartbeats renovam o lease e stop o libera, sempre conferindo se a sessão ainda é a titular.

## Backend de cache

O código usa `Cache::add`, `Cache::get`, `Cache::put`, `Cache::forget` e `Cache::lock`. Portanto:

- Redis é recomendado para produção e múltiplas instâncias.
- O cache `database` também funciona se as tabelas de cache/locks existirem e todos os nós compartilharem o banco.
- Não use cache local por processo em ambiente com mais de um servidor.

Locks auxiliares usam `rec:game:{id}:camera:{tag}:lock`.

## Verificação

Um `409` ao iniciar/renovar indica câmera ocupada ou lease perdido. Inspecione a sessão com `php artisan rec:inspect-session {uuid}` e confirme heartbeat/expiração antes de remover qualquer chave manualmente.
