# Observabilidade

## Logs

Filtre `storage/logs/laravel.log` por `REC`. V1 registra start/stop, SAVE, upload, duplicidade, broadcast e FFmpeg. V2 registra falhas do processamento e persiste contexto operacional em `rec_operational_events`.

## Comandos

```bash
php artisan rec:health --force
php artisan rec:inspect-session {session_uuid}
php artisan rec:inspect-save {save_uuid}
php artisan rec:reconcile --dry-run
php artisan rec:reconcile --fix --save={save_uuid}
php artisan rec:expire-sessions --sync
php artisan rec:cleanup --sync
```

`rec:health` mostra FFmpeg, flags, disco e fila. `rec:reconcile` aceita também `--game={id}`.

## Sinais importantes

- Idade do último heartbeat e leases expirados.
- Quantidade/idade de uploads locais e jobs de fila.
- SAVEs em `collecting`, `processing` ou `partial` além do deadline.
- Targets com segmentos recebidos sem clip.
- Clips `failed`, códigos de falha e jobs falhos.
- Espaço e permissão no storage; latência de preview/final.
- Outbox `pending`/tentativas elevadas.
