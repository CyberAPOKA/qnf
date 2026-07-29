# Deploy do REC

## Checklist

1. Configure as variáveis `REC_*` a partir de `.env.example`.
2. Execute:

```bash
php artisan migrate --force
php artisan storage:link
php artisan config:clear
php artisan rec:health --force
```

3. Garanta o worker:

```bash
php artisan queue:work --queue=rec-video-processing,default
```

(Supervisor deve incluir `rec-video-processing` na lista de filas.)

4. Confirme o scheduler (`schedule:run` a cada minuto).
5. Valide FFmpeg/ffprobe e espaço em disco.
6. Faça `npm ci && npm run build` se o frontend for buildado no servidor.
7. Monitore sessões, uploads, fila, previews e finais.
