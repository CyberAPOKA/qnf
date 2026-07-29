# Frontend do REC

`resources/js/Pages/Rec.vue` é a única tela do REC MODE.

## Composables (`resources/js/composables/rec/`)

- `useRecCapture.js` — MediaRecorder, segmentos, wake lock, fallback sem áudio.
- `useRecSegmentStore.js` — IndexedDB (`qnf-rec`).
- `useRecUploadQueue.js` — fila persistente de upload com backoff.
- `useRecSession.js` — sessão, token, heartbeat, polling, SAVE/ack, Echo.
- `useRecHealth.js` — status da câmera (aquecendo / saudável / degradado).
- `recConfig.js` — defaults alinhados a `config/rec.php`.

## Componentes (`resources/js/Components/Rec/`)

- Seletor de ângulo, estágio (preview + tela cheia), controles SAVE, lista de clips, uploads pendentes, saúde da câmera.
