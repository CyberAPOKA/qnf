# FFmpeg no REC

Pré-requisitos no PATH do PHP/worker:

```bash
ffmpeg -version
ffprobe -version
php artisan rec:health --force
```

## Pipeline V2

1. `FinalizeRecSaveTarget` concatena/reencoda segmentos e cria o raw.
2. `BuildRecClipPreview` gera WebM em altura `REC_PREVIEW_HEIGHT` (480) e bitrate `REC_PREVIEW_VIDEO_BITRATE` (700k).
3. `BuildRecClipFinal` gera WebM com `REC_FINAL_VIDEO_BITRATE` (1600k).
4. Áudio usa Opus e `REC_AUDIO_BITRATE` (96k); se concat A/V falhar, existe tentativa somente vídeo.

Os jobs usam `REC_PROCESSING_QUEUE=rec-video-processing`, três tentativas e timeouts de 180 s (raw/preview) ou 300 s (final).

V1 usa o mesmo serviço, porém normaliza/concatena de forma síncrona durante o upload.

## Falhas

Procure `preview_failed`, `final_failed` e logs iniciados por `REC`. Confirme que os segmentos existem, têm tamanho > 0, são legíveis pelo usuário do worker e que codecs `libvpx`/`libopus` estão disponíveis.
