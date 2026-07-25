# Troubleshooting rápido

## Diagnóstico básico

```bash
php artisan rec:health --force
php artisan queue:failed
php artisan rec:reconcile --dry-run
php artisan schedule:list
```

## Câmera não inicia

- Confirme HTTPS, permissão de câmera/microfone e suporte a MediaRecorder.
- Verifique tag ocupada, cache compartilhado e lease.
- Consulte resposta 409/403 e `rec:inspect-session`.

## Segmentos não chegam

- DevTools → IndexedDB `qnf-rec`: veja `segments` e `uploadJobs`.
- Confirme online, token, timeout, payload máximo e status HTTP.
- Um `permanent_failed` exige corrigir a causa; retries automáticos cobrem falhas transitórias.

## SAVE não conclui

- `php artisan rec:inspect-save {uuid}`
- Verifique `expected_count`, targets, `segments_received`, deadline e clip.
- Confirme worker `rec-video-processing`.
- Use reconciliação com `--fix` somente após o dry-run.

## Vídeo não reproduz

- Confira existência/tamanho/path, `storage:link`, MIME e permissões.
- Rode `ffprobe arquivo.webm`.
- Procure falhas de `libvpx`, `libopus`, preview/final nos logs.

## Mitigação

Se houver impacto geral, defina `REC_V2_ENABLED=false`, aplique a configuração e preserve banco, storage e IndexedDB.
