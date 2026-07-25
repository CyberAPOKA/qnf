# Frontend do REC

`resources/js/Pages/Rec.vue` escolhe V1 ou V2 pela prop `rec_v2_enabled`, mantendo a mesma interface.

## Composables V2 (`resources/js/composables/rec/`)

- `recConfig.js`: defaults e normalização da configuração enviada pelo backend.
- `useRecCapture.js`: câmera/microfone, MediaRecorder, rotação dos segmentos, wake lock e health watchdog.
- `useRecSegmentStore.js`: persistência IndexedDB.
- `useRecUploadQueue.js`: upload idempotente, checksum, confirmação, retry com backoff e recuperação online.
- `useRecSessionV2.js`: sessão, token, heartbeat, polling, SAVE/ack, Echo e restauração após reload.
- `useRecHealth.js`: estados `healthy`, `warming_up`, `degraded`, `offline` e `critical`.

V1 continua em `composables/useRecBuffer.js` e `composables/useRecSession.js`.

## Componentes (`resources/js/Components/Rec/`)

- `RecCameraStage`, `RecCameraPositionSelector`: captura, posição, fullscreen e ações.
- `RecCameraHealthCard`, `RecActiveCameras`: saúde e câmeras ativas.
- `RecSaveControls`: SAVE por lado/all e início/fim.
- `RecPendingUploads`: fila local V2.
- `RecSaveList`, `RecSaveCard`, `RecClipPlayer`: progresso e reprodução.

O frontend escuta `SaveClipRequested`, `ClipPreviewReady`, `ClipReady`, `RecorderJoined` e `RecorderLeft` no canal privado do jogo, com polling como recuperação.
