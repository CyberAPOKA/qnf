# IndexedDB do REC V2

Banco: `qnf-rec`, versão 1.

## Object stores

- `segments` (`keyPath: uuid`): Blob, sessão, câmera, sequência, timestamps, duração, MIME, bytes e estado de verificação.
  - Índices: `sessionUuid`, `[sessionUuid, sequence]` único e `[sessionUuid, endedAt]`.
- `uploadJobs` (`keyPath: id`): fila, tentativas, prioridade, próximo retry e SAVEs associados.
  - Índices: `status`, `sessionUuid`, `segmentUuid`.
- `sessionMeta` (`keyPath: id`): sessão atual, token e metadados para retomar após reload.
- `processedKeys` (`keyPath: key`): deduplicação local de SAVEs processados; índice `processedAt`.

## Garantias práticas

- Persista o segmento antes de tentar upload.
- Só apague o Blob depois de o servidor responder/verificar `verified`.
- Falhas transitórias usam backoff de 1 s até 5 min, com jitter.
- Ao voltar online ou recarregar a página, a fila e a sessão são retomadas.

## Diagnóstico no navegador

DevTools → Application → IndexedDB → `qnf-rec`. Verifique `uploadJobs` parados, `sessionMeta.current` e crescimento de `segments`. Não limpe o banco durante incidente antes de preservar os blobs necessários.
