# Troubleshooting REC

## Câmera não abre no celular

- Use Safari/Chrome do sistema (não o browser do Instagram/WhatsApp).
- REC MODE precisa de HTTPS.
- A câmera abre **antes** do registro da sessão (gesto do usuário).

## Tela cheia no iPhone

Usa fallback CSS (`rec-stage--fullscreen`) quando a Fullscreen API falha.

## Buffer “Aquecendo” por muito tempo

O alvo é `REC_BUFFER_SECONDS` (30s). O contador **Buffer** no card de saúde deve subir. Se ficar em 0–5s, segmentos não estão persistindo (MediaRecorder/IndexedDB).

## SAVE sem preview/final

1. Worker escuta `rec-video-processing`?
2. `php artisan rec:health --force` — FFmpeg yes?
3. `php artisan queue:failed`
4. `php artisan rec:inspect-save {uuid}`

## Worker

Supervisor deve ter:

```text
--queue=rec-video-processing,default
```
