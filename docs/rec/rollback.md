# Rollback do REC

Não há flag para “voltar ao V1”. Em incidente:

1. Preserve banco, storage e IndexedDB dos aparelhos.
2. Corrija o worker / FFmpeg / rotas.
3. Use `php artisan rec:health --force` e os comandos `rec:inspect-*`.
4. Se necessário, desative temporariamente o link REC no dashboard (deploy de código), sem apagar dados.
