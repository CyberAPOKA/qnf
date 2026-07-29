# Resposta a incidentes REC

1. Capture `php artisan rec:health --force` e logs do worker.
2. Inspecione: `rec:inspect-session` / `rec:inspect-save`.
3. Verifique fila: jobs failed, Supervisor com `rec-video-processing`.
4. Preserve banco, storage e IndexedDB.
5. Não apague segmentos/clips até entender a falha.
6. Corrija e redeploy; reteste com uma câmera antes de liberar a partida.
