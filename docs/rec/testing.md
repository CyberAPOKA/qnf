# Testes do REC

Execute a suíte focada:

```bash
php artisan test --filter=Rec
```

Antes de ativar em produção, valide manualmente:

1. V1 continua funcional com `REC_V2_ENABLED=false`.
2. Uma câmera V2 inicia, mantém heartbeat e recupera sessão após reload.
3. Duas câmeras não ocupam o mesmo tag.
4. Segmentos aparecem no IndexedDB, são enviados e apagados só após `verified`.
5. SAVE `left`, `all` e `right` cria apenas targets esperados.
6. Dois SAVEs separados por mais de 800 ms são aceitos, sem cooldown global de 10 s.
7. Offline/online retoma uploads.
8. Preview aparece antes do final e ambos reproduzem.
9. Worker parado acumula jobs; ao voltar, processa sem duplicar clips.
10. `php artisan rec:health --force` retorna sucesso.

Use arquivos e jogo de teste. Não limpe dados de produção para validar retenção.
