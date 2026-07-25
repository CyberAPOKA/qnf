# Deploy do REC V2

## Checklist

1. Publique código com `REC_V2_ENABLED=false`.
2. Configure as variáveis `REC_*` a partir de `.env.example`.
3. Execute:

```bash
php artisan migrate --force
php artisan storage:link
php artisan config:clear
php artisan rec:health --force
php artisan test --filter=Rec
```

4. Inicie/supervisione o worker:

```bash
php artisan queue:work --queue=rec-video-processing,default
```

5. Confirme o scheduler.
6. Valide permissões/espaço do storage e FFmpeg/ffprobe.
7. Habilite `REC_V2_ENABLED=true` em rollout gradual: um ambiente/jogo/grupo por vez conforme a estratégia de configuração disponível.
8. Monitore sessões, uploads, fila, previews, finais e falhas.

O frontend recebe a flag no carregamento da página; após alterá-la, limpe/recrie o cache de configuração e recarregue os clientes. Não habilite V2 sem migrations e worker ativos.
