# Filas do REC

Worker recomendado:

```bash
php artisan queue:work --queue=rec-video-processing,default
```

Em produção, execute-o sob Supervisor/systemd e reinicie após deploy:

```bash
php artisan queue:restart
```

`REC_PROCESSING_QUEUE` define a fila usada por publicação de outbox, finalização de target, preview, final e reconciliação. O nome padrão é `rec-video-processing`.

## Operação

- Mantenha pelo menos um worker dedicado; FFmpeg consome CPU, memória e disco.
- Ajuste concorrência conforme os recursos do servidor.
- Monitore jobs pendentes/falhos e tempo até preview/final.
- O scheduler deve estar ativo (`php artisan schedule:run` a cada minuto): expira sessões/segmentos e reconcilia SAVEs.
- Após alterar `.env`, execute `php artisan config:clear` ou reconstrua o cache de configuração conforme o processo de deploy.
