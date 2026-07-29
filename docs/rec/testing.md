# Testes do REC

```bash
php artisan test --filter=Rec
```

Cobertura principal:

- exclusividade de câmera (409)
- upload de segmento idempotente
- heartbeat com token inválido
- SAVE por escopo left/right/all e cooldown por lado
- SAVE só com câmeras ativas no escopo
- pin de segmentos na janela do SAVE

Antes de produção: migrar, worker com `rec-video-processing`, FFmpeg, build do frontend, teste manual em Android e iPhone.
