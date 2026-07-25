# Rollback operacional

Rollback preferido:

```dotenv
REC_V2_ENABLED=false
```

Depois, aplique a configuração:

```bash
php artisan config:clear
```

ou reconstrua o cache conforme o deploy, e recarregue a página REC. Novas sessões usarão o fluxo V1.

## Preservação

- Não reverta migrations nem apague tabelas/arquivos durante o incidente.
- Mantenha o worker temporariamente se quiser concluir jobs V2 já aceitos; pare-o somente se ele estiver causando o incidente.
- Preserve IndexedDB dos dispositivos quando houver uploads pendentes.
- Registre UUIDs de sessão/SAVE e horário da mudança.

O rollback da flag reduz o risco sem destruir evidências. Depois da estabilização, use `rec:inspect-*` e `rec:reconcile --dry-run` para avaliar o backlog V2.
