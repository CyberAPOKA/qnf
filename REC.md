# REC — operação

Fluxo único (sem V1/V2): segmentos contínuos no celular, upload persistente, SAVE com targets, FFmpeg na fila.

## Pré-requisitos

- Migrations aplicadas
- Worker: `php artisan queue:work --queue=rec-video-processing,default`
- Scheduler ativo
- FFmpeg/ffprobe no PATH
- Variáveis `REC_*` do `.env.example`

## Health

```bash
php artisan rec:health --force
```

## Comandos úteis

- `php artisan rec:expire-sessions --sync`
- `php artisan rec:cleanup --sync`
- `php artisan rec:reconcile --force`
- `php artisan rec:inspect-session {uuid}`
- `php artisan rec:inspect-save {uuid}`

## Docs

Detalhes em `docs/rec/` (architecture, deployment, queues, storage, troubleshooting).