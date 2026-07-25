# Resposta a incidentes

## Primeiros passos

1. Anote jogo, câmera, sessão/SAVE UUID, horário e aparelho.
2. Execute `php artisan rec:health --force`.
3. Inspecione `rec:inspect-session` e `rec:inspect-save`.
4. Verifique worker, jobs falhos, logs `REC`, storage e IndexedDB.
5. Se o impacto for amplo, defina `REC_V2_ENABLED=false`; preserve dados.

## Incidentes comuns

- **Câmera ocupada (409):** confirme titular e `lease_expires_at`; aguarde expiração ou pare a sessão correta.
- **Uploads acumulados:** confira rede, token/sessão, `uploadJobs`, limites HTTP e espaço em disco.
- **SAVE sem câmera:** valide sessão ativa, heartbeat, lease e escopo/tag.
- **SAVE parado:** rode `rec:reconcile --dry-run --save={uuid}`; após entender, use `--fix`.
- **Preview/final ausente:** valide worker, FFmpeg, segmentos, permissões e status/código de falha.
- **Arquivos quebrados:** compare paths do banco com storage; não apague registros antes de preservar evidência.
- **Disco cheio:** interrompa ativação, preserve artefatos de SAVE e execute limpeza somente conforme retenção.
- **Broadcast falhou:** polling deve recuperar estado; verifique Echo/Reverb sem repetir SAVE manualmente.

Documente causa, impacto, ação, dados preservados e critério de encerramento.
