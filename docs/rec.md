## PAPEL

Você é um engenheiro de software sênior responsável por transformar o módulo de gravação de partidas de futsal em um sistema altamente confiável, resiliente, rápido, auditável e seguro.

O sistema usa Laravel no backend, Vue 3 com Inertia no frontend, Laravel Echo para eventos em tempo real, MediaRecorder no navegador e FFmpeg para tratamento dos vídeos.

Não faça apenas uma análise. Implemente todas as mudanças necessárias no código existente, incluindo migrations, models, services, controllers, requests, jobs, events, listeners, policies, rotas, composables, telas, testes, configurações, documentação e scripts de implantação.

O módulo registra quatro celulares posicionados como câmeras:

- A1
- A2
- B1
- B2

Qualquer usuário autenticado na partida pode disparar um SAVE. O sistema deve preservar os segundos anteriores ao clique, permitir revisão rápida do lance e continuar funcionando mesmo com falhas temporárias de rede, WebSocket, Redis, FFmpeg, navegador ou servidor.

Cada gravação perdida representa um momento irrepetível. Portanto, nenhuma falha pode ser silenciosa.

---

# CONTEXTO DO CÓDIGO ATUAL

Localize e analise, no mínimo, os seguintes arquivos e seus equivalentes reais no projeto:

## Backend

- `app/Models/RecSaveRequest.php`
- `app/Models/RecClip.php`
- `app/Services/RecSessionService.php`
- `app/Services/RecClipNormalizeService.php`
- `app/Http/Controllers/RecController.php`
- eventos:
  - `SaveClipRequested`
  - `ClipReady`
  - `RecorderJoined`
  - `RecorderLeft`
- canais privados do Laravel Echo
- rotas relacionadas a:
  - `games.rec.start`
  - `games.rec.stop`
  - `games.rec.heartbeat`
  - `games.rec.save`
  - `games.rec.upload`
- migrations atuais de `rec_save_requests` e `rec_clips`
- configuração de filesystem
- configuração de filas
- configuração de broadcasting
- configuração de Redis
- policies e autorização da partida

## Frontend

- `resources/js/Pages/Rec.vue`
- `resources/js/composables/useRecBuffer.js`
- `resources/js/composables/useRecSession.js`
- configuração do Axios
- configuração do Laravel Echo
- service worker, PWA e lifecycle do aplicativo, caso existam
- layout principal e tratamento global de erros

Não presuma nomes de arquivos que não existam. Primeiro inspecione a estrutura real do repositório e adapte a implementação ao padrão já adotado no projeto.

---

# OBJETIVOS NÃO NEGOCIÁVEIS

A solução final deve atender simultaneamente aos seguintes objetivos:

1. Não depender somente do WebSocket para entregar uma solicitação de SAVE.
2. Não depender somente da memória da página para manter vídeos pendentes.
3. Não perder gravações quando a internet cair temporariamente.
4. Não perder gravações ao atualizar a página ou reabrir o sistema.
5. Não bloquear um segundo lance importante por causa de cooldown longo.
6. Permitir SAVEs consecutivos e sobrepostos.
7. Garantir exclusividade real das posições A1, A2, B1 e B2.
8. Impedir que um usuário manipule o `recorder_id` de outro dispositivo.
9. Tornar uploads idempotentes.
10. Executar FFmpeg fora da requisição HTTP.
11. Exibir o vídeo para revisão o mais rápido possível.
12. Manter o arquivo original até confirmar a geração correta do resultado.
13. Registrar o estado de cada câmera esperada em cada SAVE.
14. Mostrar claramente quando um clip está pronto, parcial, atrasado ou falhou.
15. Possuir testes automatizados para concorrência, duplicidade, falhas e recuperação.
16. Ser compatível com celulares Android e, quando possível, iPhone/Safari.
17. Ter fallback para gravação sem áudio.
18. Ter telemetria suficiente para diagnosticar qualquer perda.
19. Não introduzir migrations destrutivas ou alterações irreversíveis sem estratégia de rollback.
20. Manter o módulo atual utilizável durante a migração para a nova arquitetura.

---

# REGRAS DE EXECUÇÃO

1. Antes de alterar o código, faça um inventário técnico da implementação atual.
2. Verifique a versão real do Laravel, PHP, Vue, Axios, Echo, Redis, banco e FFmpeg.
3. Não use APIs incompatíveis com as versões instaladas.
4. Não remova rotas antigas antes de existir compatibilidade.
5. Use feature flags para ativação gradual.
6. Crie migrations incrementais.
7. Use índices únicos e foreign keys.
8. Use transações em operações relacionadas.
9. Não esconda exceções críticas com `rescue(..., report: false)` sem criar uma alternativa confiável.
10. Não use arrays inteiros em cache para controlar sessões concorrentes.
11. Não use `localStorage` para armazenar blobs de vídeo.
12. Use IndexedDB ou OPFS no navegador.
13. Não considere um upload concluído antes de receber confirmação persistida do servidor.
14. Não apague dados locais antes da confirmação do servidor.
15. Não apague o arquivo bruto no servidor antes de validar o arquivo processado.
16. Não faça retry imediato em loop.
17. Use exponential backoff com jitter.
18. Diferencie falha temporária de falha permanente.
19. Não marque o SAVE inteiro como falho porque uma única câmera atrasou.
20. Não use somente logs no console como observabilidade.
21. Evite comentários redundantes no código.
22. Preserve o estilo, lint e convenções já usados no projeto.
23. Após cada fase, rode testes, lint e build.
24. Corrija todos os erros encontrados.
25. Entregue também uma documentação operacional.

---

# RESULTADO ARQUITETURAL ESPERADO

A arquitetura final deve combinar duas camadas de proteção:

## Camada 1 — Buffer local persistente

Cada câmera grava segmentos continuamente.

Cada segmento deve:

- possuir sequência crescente;
- possuir identificador idempotente;
- ser salvo no IndexedDB ou OPFS;
- conter metadados de tempo;
- permanecer localmente até o servidor confirmar o recebimento;
- sobreviver a atualização da página;
- ser reenviado após retorno da conexão;
- fazer parte de uma fila persistente;
- ter checksum;
- possuir estado local explícito.

## Camada 2 — Buffer temporário no servidor

Os segmentos devem ser enviados continuamente para o servidor antes do clique em SAVE.

O servidor mantém uma janela temporária por câmera, por exemplo:

- buffer útil padrão: 30 segundos;
- retenção temporária: 90 a 120 segundos;
- segmento padrão: 5 segundos;
- margem pré-SAVE configurável;
- margem pós-SAVE configurável.

Ao clicar em SAVE, o servidor deve fixar os segmentos correspondentes ao intervalo desejado.

O SAVE não pode depender apenas de uma nova captura iniciada após o clique.

## Caminho rápido e caminho de recuperação

Implemente:

### Caminho rápido

- WebSocket notifica imediatamente as câmeras.
- O servidor já possui a maior parte dos segmentos.
- A câmera envia imediatamente o segmento atual ainda não finalizado ou os segmentos pendentes.
- O processamento rápido gera um preview para revisão.
- O preview aparece assim que estiver reproduzível.

### Caminho de recuperação

- Polling HTTP encontra SAVEs não reconhecidos.
- IndexedDB mantém os segmentos.
- Uploads continuam após reconexão.
- O servidor consegue montar o clip usando segmentos já enviados.
- Caso faltem segmentos, a câmera recebe uma solicitação específica de recuperação.
- O sistema registra exatamente quais segmentos estão faltando.

---

# FEATURE FLAGS E CONFIGURAÇÕES

Adicione configurações equivalentes às seguintes, adaptadas ao padrão do projeto:

```env
REC_CONTINUOUS_SEGMENTS_ENABLED=false
REC_SEGMENT_SECONDS=5
REC_BUFFER_SECONDS=30
REC_SERVER_RETENTION_SECONDS=120
REC_LOCAL_RETENTION_SECONDS=180
REC_POST_ROLL_SECONDS=2
REC_HEARTBEAT_SECONDS=10
REC_RECORDER_LEASE_SECONDS=35
REC_SAVE_DEBOUNCE_MILLISECONDS=800
REC_PENDING_SAVE_POLL_SECONDS=2
REC_UPLOAD_MAX_CONCURRENCY=1
REC_UPLOAD_REQUEST_TIMEOUT_SECONDS=120
REC_PROCESSING_QUEUE=rec-video-processing
REC_STORAGE_DISK=s3
REC_TEMP_STORAGE_DISK=local
REC_PREVIEW_HEIGHT=480
REC_PREVIEW_VIDEO_BITRATE=700k
REC_FINAL_VIDEO_BITRATE=1600k
REC_AUDIO_BITRATE=96k
REC_RAW_RETENTION_DAYS=7
REC_FAILED_RETENTION_DAYS=30
REC_METRICS_ENABLED=true
```

Crie um arquivo de configuração dedicado, por exemplo:

```text
config/rec.php
```

Todos os números operacionais devem vir da configuração. Evite constantes duplicadas no PHP e JavaScript.

Exponha somente as configurações necessárias ao frontend.

---

# NOVO MODELO DE DADOS

Inspecione as tabelas existentes e crie migrations incrementais.

Não remova imediatamente `rec_save_requests` e `rec_clips`. Evolua essas tabelas ou crie tabelas novas, mantendo compatibilidade.

## 1. `rec_recorder_sessions`

Crie uma tabela equivalente a:

```text
id
uuid
game_id
user_id
camera_tag
status
session_token_hash
started_at
heartbeat_at
lease_expires_at
buffer_ready_at
buffer_available_ms
last_segment_sequence
last_segment_received_at
last_client_event_at
estimated_clock_offset_ms
estimated_rtt_ms
mime_type
container
video_codec
audio_codec
width
height
fps
has_audio
user_agent
device_fingerprint_hash
failure_code
failure_message
stopped_at
created_at
updated_at
```

Requisitos:

- `uuid` único.
- `game_id + camera_tag` não deve permitir duas sessões ativas simultâneas.
- Como índices parciais não existem em todos os bancos, a exclusividade ativa deve ser garantida por lease atômica no Redis e também validada no banco.
- Índices para:
  - `game_id`
  - `game_id, camera_tag`
  - `game_id, status`
  - `lease_expires_at`
  - `user_id`
- Enum ou constantes de status:
  - `starting`
  - `recording`
  - `degraded`
  - `reconnecting`
  - `stopped`
  - `expired`
  - `failed`

## 2. `rec_segments`

Crie uma tabela equivalente a:

```text
id
uuid
recorder_session_id
game_id
sequence
idempotency_key
client_started_at
client_ended_at
estimated_server_started_at
estimated_server_ended_at
duration_ms
file_path
storage_disk
mime_type
container
video_codec
audio_codec
bytes
checksum_sha256
status
upload_attempts
received_at
verified_at
pinned_until
failure_code
failure_message
created_at
updated_at
```

Requisitos:

- `uuid` único.
- `idempotency_key` único.
- `recorder_session_id + sequence` único.
- Estados:
  - `announced`
  - `uploading`
  - `received`
  - `verified`
  - `pinned`
  - `expired`
  - `failed`
- Índices para consultas por:
  - sessão e sequência;
  - jogo e intervalo de tempo;
  - status;
  - `pinned_until`;
  - retenção.

## 3. Evolução de `rec_save_requests`

Garanta campos equivalentes a:

```text
uuid
game_id
triggered_by
capture_scope
status
triggered_at
capture_from
capture_until
expected_count
acknowledged_count
received_count
ready_count
failed_count
deadline_at
processing_started_at
completed_at
failure_code
failure_message
created_at
updated_at
```

Estados:

- `requested`
- `collecting`
- `processing`
- `partial`
- `ready`
- `failed`
- `cancelled`

Requisitos:

- `uuid` único.
- `triggered_at` deve usar horário do servidor.
- `capture_from` e `capture_until` devem ser persistidos.
- SAVEs sobrepostos devem ser permitidos.
- Remover o cooldown global de 10 segundos.
- Manter apenas debounce curto contra duplo clique acidental.

## 4. `rec_save_targets`

Crie uma linha para cada câmera esperada no momento do SAVE:

```text
id
rec_save_request_id
recorder_session_id
camera_tag
status
expected_from
expected_until
acknowledged_at
segments_expected
segments_received
segments_missing
raw_ready_at
preview_ready_at
final_ready_at
last_error_at
failure_code
failure_message
created_at
updated_at
```

Estados:

- `waiting_ack`
- `collecting`
- `raw_ready`
- `processing`
- `preview_ready`
- `ready`
- `partial`
- `failed`
- `camera_offline`

Índices únicos:

```text
rec_save_request_id + recorder_session_id
rec_save_request_id + camera_tag
```

## 5. Evolução de `rec_clips`

Adicione ou garanta:

```text
rec_save_target_id
raw_file_path
preview_file_path
final_file_path
storage_disk
status
duration_ms
bytes
checksum_sha256
mime_type
container
video_codec
audio_codec
processing_attempts
processing_started_at
processing_finished_at
failure_code
failure_message
created_at
updated_at
```

Estados:

- `pending`
- `raw_ready`
- `processing`
- `preview_ready`
- `ready`
- `failed`

Mantenha compatibilidade temporária com `file_path` e `duration_seconds`.

## 6. Tabela opcional de eventos operacionais

Crie `rec_operational_events` se o projeto não possuir uma infraestrutura equivalente:

```text
id
game_id
recorder_session_id
rec_save_request_id
rec_save_target_id
rec_segment_id
level
event_type
message
context_json
occurred_at
created_at
```

Use para auditoria, não para substituir logs estruturados.

---

# EXCLUSIVIDADE DAS CÂMERAS COM LEASE NO REDIS

Não controle os gravadores como um único array em cache.

Use uma chave por posição:

```text
rec:game:{gameId}:camera:{cameraTag}
```

O valor deve identificar a sessão:

```json
{
  "session_uuid": "...",
  "user_id": 123,
  "camera_tag": "A1"
}
```

## Registro

A aquisição deve ser atômica.

Use Redis diretamente, Lua script ou uma operação equivalente a:

```text
SET key session_uuid NX EX lease_seconds
```

Se já estiver ocupada:

- valide se a sessão expirou;
- não sobrescreva uma sessão ativa;
- retorne HTTP 409;
- informe qual posição está ocupada;
- não exponha dados sensíveis.

## Heartbeat

O heartbeat deve renovar a lease somente se o valor atual ainda pertencer à mesma sessão.

Não use uma sequência não atômica de GET e EXPIRE.

Use Lua ou compare-and-expire atômico.

## Stop

O stop deve apagar a chave somente se ela ainda pertencer à sessão solicitante.

## Expiração

Crie uma rotina para marcar sessões expiradas no banco.

## Recuperação

Se o navegador voltar e possuir uma sessão persistida:

- validar a sessão;
- renovar a lease se ainda for dona da posição;
- reconstruir a fila local;
- continuar os uploads;
- não criar câmera fantasma;
- se a lease tiver sido assumida por outra câmera, encerrar a sessão antiga e preservar os uploads pendentes.

---

# AUTENTICAÇÃO DA SESSÃO DA CÂMERA

Não confie apenas em `recorder_id`.

No registro da câmera:

1. Crie `recorder_session_uuid`.
2. Gere um token aleatório forte.
3. Salve apenas o hash no banco.
4. Retorne o token bruto apenas uma vez.
5. Persista o token localmente em IndexedDB.
6. Exija o token em:
   - heartbeat;
   - stop;
   - anúncio de segmento;
   - upload de segmento;
   - ACK de SAVE;
   - recuperação de segmentos;
   - consulta do estado da sessão.
7. Associe a sessão ao usuário autenticado.
8. Valide jogo, usuário, posição e sessão.
9. Faça rotação do token quando necessário.
10. Nunca coloque o token em URL ou logs.

Use header dedicado, por exemplo:

```text
X-REC-Session: session_uuid
X-REC-Token: token
```

Proteja contra replay usando idempotency key e validação da sessão.

---

# SINCRONIZAÇÃO DE TEMPO

O SAVE usa horário do servidor. Os segmentos são gerados no cliente. É necessário estimar a diferença de relógio.

Implemente um mecanismo simples de time sync:

1. O cliente envia `client_sent_at_ms`.
2. O servidor responde:
   - `server_received_at_ms`;
   - `server_sent_at_ms`.
3. O cliente registra `client_received_at_ms`.
4. Calcule RTT.
5. Estime o offset usando o ponto médio.
6. Use múltiplas amostras.
7. Descarte amostras com RTT muito alto.
8. Use mediana ou melhor amostra recente.
9. Armazene offset e RTT na sessão.
10. Cada segmento envia:
    - tempo local;
    - tempo monotônico;
    - tempo estimado do servidor.

Não dependa somente de `Date.now()` sem compensação.

O backend deve usar uma margem de segurança configurável ao selecionar segmentos.

---

# BUFFER E GRAVAÇÃO NO FRONTEND

Reestruture `useRecBuffer.js`.

## Problemas que devem ser eliminados

- Não manter apenas um `previousSegment`.
- Não perder o segmento atual após um SAVE.
- Não impedir SAVE antes de 25 segundos.
- Não marcar um SAVE como processado antes de capturar.
- Não depender somente de memória.
- Não apagar segmentos sem confirmação.
- Não permitir interleaving entre rotação e snapshot.
- Não criar gaps entre segmentos.
- Não depender de um timer impreciso sem medir duração real.

## Estrutura esperada

Crie componentes internos claros:

```text
useRecCapture
useRecSegmentStore
useRecUploadQueue
useRecSession
useRecSaveRequests
useRecHealth
```

Os nomes podem ser adaptados ao projeto, mas separe responsabilidades.

## Segmentação

Configuração padrão:

```text
segmento: 5 segundos
buffer de SAVE: 30 segundos
retenção local mínima: 180 segundos
```

Cada segmento deve ter:

```text
uuid
session_uuid
sequence
started_at_client_ms
ended_at_client_ms
started_at_monotonic_ms
ended_at_monotonic_ms
estimated_server_started_at_ms
estimated_server_ended_at_ms
duration_ms
mime_type
blob
bytes
checksum
status
attempts
next_retry_at
created_at
```

## IndexedDB ou OPFS

Crie um repositório local com:

- versionamento de schema;
- transações;
- índices por sessão, sequência, status e horário;
- recuperação após reload;
- limpeza segura;
- limite máximo de armazenamento;
- aviso de quota;
- método para estimar uso;
- método para reprocessar itens presos;
- estados persistidos.

Biblioteca externa somente se já estiver aprovada no projeto ou se trouxer benefício claro. Caso use uma biblioteca, justifique na documentação e fixe a versão.

## Estado local dos segmentos

Use estados como:

- `recording`
- `stored`
- `queued`
- `uploading`
- `uploaded`
- `verified`
- `pinned`
- `failed_retryable`
- `failed_permanent`

## Política de remoção local

Um segmento pode ser removido localmente somente quando:

1. O servidor confirmou checksum.
2. O segmento não pertence a um SAVE pendente.
3. A retenção mínima foi cumprida.
4. Não existe solicitação de recuperação.
5. A transação de remoção concluiu.

Em caso de pressão de armazenamento:

1. remover segmentos confirmados e não fixados;
2. remover segmentos antigos já finalizados;
3. nunca remover primeiro os segmentos não enviados;
4. exibir erro crítico se não houver espaço seguro.

## Áudio opcional

Tente iniciar com áudio e vídeo.

Se o áudio falhar:

- tente novamente somente com vídeo;
- informe `has_audio=false`;
- continue gravando;
- não considere a câmera totalmente indisponível.

## MIME e extensão

Não envie sempre `.webm`.

Mapeie:

```text
video/webm -> .webm
video/mp4 -> .mp4
```

Armazene MIME, container e codecs.

## Tracks

Monitore:

- `track.onended`;
- `track.onmute`;
- `track.onunmute`;
- estado `readyState`;
- chegada real de bytes;
- ausência de `dataavailable`;
- câmera removida;
- permissão revogada.

Crie watchdog.

Se a captura parar:

- tente reiniciar com limite;
- preserve o que já foi capturado;
- marque a câmera como degradada;
- envie evento operacional;
- mostre alerta visível;
- não mantenha indicador REC falso.

## Wake Lock

Use Screen Wake Lock quando disponível.

- adquirir ao iniciar REC;
- recuperar após `visibilitychange`;
- liberar ao parar;
- tratar falhas sem interromper gravação.

## Background

Ao entrar em background:

- registrar horário;
- continuar quando possível;
- ao voltar, validar track, recorder e fila;
- identificar gaps;
- informar claramente eventual interrupção.

---

# FILA PERSISTENTE DE UPLOAD

Substitua a fila em memória.

## Requisitos

- Persistida em IndexedDB.
- Um job por segmento.
- Idempotency key determinística.
- Concorrência configurável.
- Ordem por sequência.
- Retry após reload.
- Retry após evento `online`.
- Retry após reconexão da sessão.
- Timeout adequado para arquivos.
- Cancelamento seguro.
- Pausa quando offline.
- Jitter.
- Backoff.
- Priorização de segmentos pertencentes a SAVE.
- Não reter blobs duplicados desnecessariamente.

## Backoff

Use uma política equivalente:

```text
1s
3s
10s
30s
60s
120s
300s
```

Depois, continuar em intervalos controlados por um prazo configurável.

Não limitar a apenas duas tentativas.

## Classificação de erros

### Retryable

- timeout;
- falha de DNS;
- offline;
- 408;
- 425;
- 429;
- 500;
- 502;
- 503;
- 504;
- conexão interrompida.

### Exigem renovação de sessão

- 401;
- token expirado;
- sessão ainda pertencente ao mesmo usuário.

### Permanentes

- arquivo inválido;
- checksum incompatível persistente;
- posição incorreta;
- jogo incorreto;
- sessão não autorizada;
- payload excede limite definitivo.

## Checksum

Calcule SHA-256 no cliente quando suportado.

O servidor deve:

- calcular novamente;
- comparar;
- rejeitar divergências;
- não confirmar o segmento antes da verificação.

## Upload idempotente

Envie:

```text
Idempotency-Key
session_uuid
segment_uuid
sequence
checksum
metadata
file
```

O servidor deve retornar o mesmo recurso se a idempotency key já tiver sido processada.

Não criar registros duplicados.

---

# PROTOCOLO DE SEGMENTOS

Crie endpoints REST equivalentes aos seguintes, respeitando os padrões das rotas existentes.

## Iniciar sessão

```http
POST /games/{game}/rec/sessions
```

Payload:

```json
{
  "camera_tag": "A1",
  "capabilities": {
    "mime_types": [],
    "width": 1280,
    "height": 720,
    "fps": 24,
    "has_audio": true
  },
  "client": {
    "user_agent": "...",
    "timezone": "...",
    "app_version": "..."
  }
}
```

Resposta:

```json
{
  "session": {
    "uuid": "...",
    "camera_tag": "A1",
    "status": "starting",
    "lease_expires_at": "...",
    "token": "retornado somente aqui"
  },
  "config": {
    "segment_seconds": 5,
    "buffer_seconds": 30,
    "retention_seconds": 120,
    "heartbeat_seconds": 10
  },
  "server_time_ms": 0
}
```

## Heartbeat

```http
POST /games/{game}/rec/sessions/{session}/heartbeat
```

Inclua:

- métricas locais;
- último sequence;
- buffer disponível;
- tamanho da fila;
- estado da câmera;
- bateria quando disponível;
- espaço local estimado;
- timestamps para time sync.

Resposta deve informar:

- lease renovada;
- horário do servidor;
- SAVEs pendentes resumidos;
- comandos de recuperação;
- configuração atualizada.

## Stop

```http
POST /games/{game}/rec/sessions/{session}/stop
```

Deve ser idempotente.

## Anunciar segmento

Pode ser combinado com upload ou separado.

```http
POST /games/{game}/rec/sessions/{session}/segments
```

O servidor deve aceitar multipart e validar metadados.

## Confirmar segmentos pendentes

```http
GET /games/{game}/rec/sessions/{session}/segments/status
```

Receba lista ou intervalo de sequências.

## Consultar SAVEs pendentes

```http
GET /games/{game}/rec/sessions/{session}/save-requests/pending
```

Suporte cursor:

```text
after_id
after_created_at
```

## ACK de SAVE

```http
POST /games/{game}/rec/sessions/{session}/save-requests/{saveRequest}/ack
```

Payload deve incluir:

- último sequence disponível;
- janela local disponível;
- segmentos locais correspondentes;
- gaps conhecidos;
- estado da captura.

## Solicitação de recuperação

```http
GET /games/{game}/rec/sessions/{session}/recovery-requests
```

Ou retorne as solicitações no heartbeat.

## Estado de um SAVE

```http
GET /games/{game}/rec/save-requests/{saveRequest}
```

Retorne targets e clips.

## Lista recente

Use paginação por cursor.

Não carregar indefinidamente todos os clips.

---

# NOVO FLUXO DE SAVE

## 1. Clique

O frontend deve:

- aplicar debounce curto;
- gerar idempotency key do clique;
- enviar `capture_scope`;
- não bloquear a partida inteira por 10 segundos;
- permitir SAVEs sobrepostos;
- mostrar confirmação imediata da criação da solicitação.

## 2. Backend

Dentro de transação:

1. Capturar `triggered_at` no servidor.
2. Calcular:
   - `capture_from`;
   - `capture_until`;
   - pós-roll configurável.
3. Consultar sessões ativas por posição.
4. Criar `rec_save_request`.
5. Criar `rec_save_targets`.
6. Fixar imediatamente segmentos já existentes.
7. Atualizar contadores.
8. Commit.
9. Publicar evento em queue/outbox.
10. Retornar resposta.

## 3. Entrega às câmeras

Use:

- WebSocket para baixa latência;
- polling HTTP para garantia;
- heartbeat com lista de pendências;
- ACK idempotente.

A câmera nunca deve depender de receber o evento uma única vez.

## 4. Pós-roll

Caso exista pós-roll:

- target permanece coletando até `capture_until`;
- segmentos novos que sobreponham a janela são fixados;
- câmera é informada do intervalo exato;
- o usuário vê “coletando últimos 2s”.

## 5. Finalização

Quando os segmentos necessários existirem:

- target vai para `raw_ready`;
- dispara job de preview;
- o SAVE pode ficar parcialmente pronto enquanto outras câmeras ainda processam;
- cada clip aparece individualmente assim que estiver reproduzível.

## 6. Timeout

Não use timeout fixo de 45 segundos para declarar falha definitiva.

Use estados:

- `aguardando`;
- `atrasado`;
- `offline`;
- `recuperando`;
- `parcial`;
- `falhou`.

Continue recuperação em background dentro de prazo configurável.

---

# GARANTIA DE ENTREGA DE EVENTOS

O WebSocket não pode ser a única fonte.

## Outbox

Implemente transactional outbox caso o projeto ainda não possua.

Ao criar eventos críticos:

- grave o evento na mesma transação;
- publique por job;
- marque como publicado;
- repita em caso de falha;
- dedupe pelo UUID do evento.

Eventos críticos:

- SAVE criado;
- target criado;
- segmento recebido;
- clip preview pronto;
- clip final pronto;
- câmera expirada;
- recuperação solicitada.

## Frontend

Ao reconectar o Echo:

1. refazer autenticação;
2. consultar SAVEs pendentes;
3. consultar estado da sessão;
4. reprocessar uploads;
5. buscar clips novos;
6. atualizar a interface.

## Eventos fora de ordem

Todos os handlers devem ser idempotentes.

Nunca use `Object.assign` de forma que um payload vazio apague clips existentes.

Faça merge por UUID e ID.

A ordem dos eventos não pode afetar o estado final.

---

# BACKEND DE UPLOAD

Crie Form Requests dedicados.

Valide:

- autenticação;
- autorização da partida;
- token de sessão;
- posição;
- sequência;
- UUID;
- idempotency key;
- MIME real;
- extensão;
- tamanho;
- duração plausível;
- checksum;
- associação ao jogo;
- sessão ativa ou recuperável.

## Persistência segura

Fluxo:

1. Receber arquivo em área temporária.
2. Calcular checksum.
3. Validar.
4. Mover para caminho definitivo temporário.
5. Criar ou atualizar registro dentro de transação.
6. Confirmar para cliente.
7. Nunca executar FFmpeg nessa requisição.
8. Nunca apagar o arquivo válido anterior antes de confirmar o novo.

## Duplicidade

Crie índices únicos no banco.

Trate conflito de chave como resposta idempotente.

Não faça somente:

```text
SELECT
INSERT
```

sem proteção contra corrida.

## Limites

Ajuste:

- PHP upload limit;
- Nginx/Apache body size;
- timeout;
- proxy timeout;
- queue timeout;
- limite por arquivo;
- rate limit por sessão.

Não use rate limit que prejudique quatro câmeras legítimas.

---

# PROCESSAMENTO COM FFMPEG

Mova todo processamento para jobs.

## Jobs sugeridos

- `FinalizeRecSaveTarget`
- `BuildRecClipPreview`
- `BuildRecClipFinal`
- `VerifyRecSegment`
- `ExpireRecSegments`
- `RecoverMissingRecSegments`
- `ExpireRecRecorderSessions`
- `ReconcileRecSaveRequest`
- `PublishRecOutboxEvent`

Adapte os nomes ao padrão do projeto.

## Queue

Use fila dedicada:

```text
rec-video-processing
```

Configure:

- concorrência controlada;
- timeout adequado;
- tries;
- backoff;
- failed jobs;
- monitoramento;
- prioridade para preview;
- prioridade secundária para versão final.

## Pipeline

### Preview rápido

Objetivo: revisão do lance.

- resolução menor, por exemplo 480p;
- bitrate menor;
- encode rápido;
- duração correta;
- geração prioritária;
- compatibilidade ampla;
- validar com ffprobe;
- publicar `ClipPreviewReady`.

### Versão final

- resolução original ou configurada;
- qualidade maior;
- MP4 H.264/AAC quando disponível;
- WebM fallback;
- rotação correta;
- metadados corretos;
- validar duração e streams;
- publicar `ClipReady`.

## Rotação e orientação

Celulares podem gravar com metadata de rotação.

Normalize corretamente.

Valide:

- width;
- height;
- rotation;
- SAR/DAR;
- orientação final.

## Concatenação

Não assuma que stream copy será sempre válido.

Implemente estratégia:

1. verificar compatibilidade dos segmentos;
2. tentar caminho rápido seguro;
3. validar resultado;
4. se inválido, reencodar;
5. nunca entregar arquivo quebrado.

## Validação

Após gerar:

- arquivo existe;
- tamanho maior que zero;
- ffprobe bem-sucedido;
- duração dentro da tolerância;
- stream de vídeo existente;
- timestamps contínuos;
- arquivo reproduzível;
- checksum calculado.

## Substituição atômica

Não sobrescreva o bruto.

Use:

```text
raw/
preview/
final/
tmp/
```

Somente atualize a referência no banco após validar.

## Falha

Em caso de falha:

- manter bruto;
- registrar stderr limitado;
- salvar failure code;
- permitir retry;
- não apagar temporários úteis antes da análise;
- limpar temporários órfãos posteriormente.

---

# STORAGE

O objetivo final é object storage, como S3.

## Requisitos

- disk configurável;
- URLs assinadas;
- arquivos privados;
- lifecycle;
- multipart upload quando necessário;
- caminhos determinísticos;
- segregação por ambiente;
- metadados;
- checksum;
- não expor caminho local.

Estrutura sugerida:

```text
rec/{environment}/games/{gameId}/sessions/{sessionUuid}/segments/{sequence}-{segmentUuid}.webm
rec/{environment}/games/{gameId}/saves/{saveUuid}/{cameraTag}/raw/...
rec/{environment}/games/{gameId}/saves/{saveUuid}/{cameraTag}/preview/...
rec/{environment}/games/{gameId}/saves/{saveUuid}/{cameraTag}/final/...
```

## Retenção

- segmentos não fixados: 90–120 segundos;
- bruto de clip: configurável;
- falhas: prazo maior para diagnóstico;
- preview e final: conforme política do produto;
- não excluir arquivos ligados a SAVEs ativos.

Crie comandos e jobs de limpeza idempotentes.

---

# SERVIÇOS DE DOMÍNIO NO BACKEND

Evite manter toda lógica no controller.

Crie serviços equivalentes a:

```text
RecRecorderSessionService
RecRecorderLeaseService
RecSegmentService
RecSaveRequestService
RecSaveTargetService
RecClipProcessingService
RecStorageService
RecTimeSyncService
RecRecoveryService
RecHealthService
```

Controllers devem apenas:

- autorizar;
- validar;
- chamar serviço;
- retornar resposta.

Use DTOs ou objetos de dados se o projeto já adotar esse padrão.

---

# AUTORIZAÇÃO E SEGURANÇA

Verifique as regras reais do projeto.

Implemente policies para:

- visualizar módulo REC;
- registrar câmera;
- disparar SAVE;
- enviar segmento;
- visualizar clips;
- excluir clips;
- administrar falhas.

Regras mínimas:

- usuário precisa ter acesso à partida;
- sessão pertence ao usuário autenticado;
- câmera pertence à partida;
- posição precisa coincidir;
- token de sessão precisa ser válido;
- URLs dos vídeos devem ser privadas;
- não confiar em MIME enviado pelo navegador;
- sanitizar nomes;
- limitar payloads;
- proteger contra path traversal;
- não logar tokens;
- não aceitar UUID de outra partida.

---

# REESTRUTURAÇÃO DO FRONTEND

## Tela `Rec.vue`

Separar a tela em componentes menores, por exemplo:

```text
RecCameraPositionSelector.vue
RecCameraStage.vue
RecCameraHealthCard.vue
RecSaveControls.vue
RecActiveCameras.vue
RecSaveList.vue
RecSaveCard.vue
RecClipPlayer.vue
RecPendingUploads.vue
```

Adapte ao padrão do projeto.

## Estados da câmera

Exibir claramente:

- iniciando;
- gravando;
- buffer enchendo;
- pronta;
- conexão instável;
- offline;
- recuperando;
- sem áudio;
- câmera interrompida;
- armazenamento local crítico;
- upload atrasado;
- sessão expirada.

Não mostrar apenas um ponto vermelho.

## Informações por câmera

Mostrar:

```text
posição
usuário
último heartbeat
buffer disponível
último segmento
segmentos pendentes
rede
áudio
resolução
bateria, quando disponível
armazenamento local
estado da lease
```

## SAVE

Ao clicar:

- feedback imediato;
- não aguardar todos os clips para mostrar o card;
- criar card em estado solicitado;
- mostrar cada posição separadamente;
- mostrar preview assim que cada câmera ficar pronta;
- permitir novo SAVE após debounce curto;
- evitar clique duplo pelo idempotency key.

## Estados por target

Exemplo:

```text
A1 — Preview pronto
A2 — Processando
B1 — Recuperando 1 segmento
B2 — Câmera offline
```

## Uploads locais

Adicionar painel de diagnóstico:

```text
3 segmentos aguardando envio
1 upload em retry
último erro
próxima tentativa
```

## Reload

Ao montar a página:

1. abrir IndexedDB;
2. recuperar sessão;
3. validar sessão no servidor;
4. reconstruir estado;
5. recuperar fila;
6. consultar SAVEs pendentes;
7. recomeçar uploads;
8. reentrar no canal Echo;
9. validar câmera física;
10. não exibir REC se a track não estiver ativa.

---

# COMPOSABLE DE SESSÃO

Reestruture `useRecSession.js`.

## Heartbeat

Não parar definitivamente no primeiro erro.

Regras:

- erro de rede: manter retry;
- 404: tentar recuperar ou registrar novamente;
- 401: renovar contexto se possível;
- 403: parar e mostrar erro;
- 409: lease perdida;
- 5xx: backoff;
- reconexão: heartbeat imediato.

## Polling

Enquanto gravando:

- polling de SAVEs pendentes;
- polling mais lento se WebSocket saudável;
- polling imediato ao reconectar;
- cursor persistido localmente.

## Dedupe

Persistir chaves processadas.

Não usar apenas `Set` em memória.

## Merge de estado

Nunca apagar clips existentes com payload parcial.

Use funções puras de merge:

- save por UUID;
- target por ID ou camera tag;
- clip por ID;
- status com versionamento ou `updated_at`.

## Versionamento

Se possível, inclua `version` incremental ou use `updated_at` para ignorar eventos antigos.

---

# DETECÇÃO DE SAÚDE

Implemente um agregador de saúde por sessão.

## Sinais

- heartbeat recente;
- lease válida;
- track ativa;
- bytes chegando;
- buffer local;
- segmentos recentes;
- uploads pendentes;
- latência;
- RTT;
- WebSocket;
- HTTP;
- quota local;
- bateria;
- falhas consecutivas.

## Estados calculados

- `healthy`
- `warming_up`
- `degraded`
- `offline`
- `critical`

## Regras

Exemplo:

- sem heartbeat: offline;
- heartbeat ativo, sem bytes: critical;
- fila atrasada, buffer local seguro: degraded;
- buffer menor que o mínimo: warming_up;
- tudo normal: healthy.

O SAVE pode continuar quando houver conteúdo parcial, mas a interface deve informar o risco.

---

# OBSERVABILIDADE

Implemente logs estruturados com IDs de correlação:

```text
game_id
session_uuid
save_request_uuid
save_target_id
segment_uuid
sequence
camera_tag
user_id
job_id
request_id
```

## Métricas

Crie métricas equivalentes a:

```text
rec_active_sessions
rec_session_heartbeat_age_seconds
rec_segment_upload_latency_seconds
rec_segment_upload_failures_total
rec_segment_retry_total
rec_local_queue_size
rec_save_requests_total
rec_save_time_to_first_preview_seconds
rec_save_time_to_all_ready_seconds
rec_save_partial_total
rec_save_failed_total
rec_ffmpeg_duration_seconds
rec_ffmpeg_failures_total
rec_missing_segments_total
rec_storage_bytes
```

Use a infraestrutura já disponível no projeto.

## Alertas

Defina documentação para alertas:

- câmera sem heartbeat;
- SAVE sem nenhum target pronto;
- fila FFmpeg acumulada;
- taxa de erro de upload;
- armazenamento cheio;
- Redis indisponível;
- S3 indisponível;
- tempo de preview acima do limite;
- jobs falhando repetidamente.

## Auditoria

Registre cada transição de estado crítica.

---

# CORREÇÕES IMEDIATAS NA IMPLEMENTAÇÃO ATUAL

Mesmo antes da arquitetura completa, corrija estes bugs:

## `useRecBuffer.js`

- substituir `previousSegment` por estrutura de segmentos;
- não perder o segmento finalizado durante snapshot;
- permitir SAVE com qualquer duração disponível;
- garantir exclusão mútua entre rotação e snapshot;
- não limpar dados úteis antes de persistir;
- usar extensão baseada no MIME;
- persistir buffer.

## `Rec.vue`

- não adicionar UUID a `handledSaveRequests` antes do enfileiramento bem-sucedido;
- remover UUID em falha retryable;
- distinguir `capturing`, `queued`, `uploading`, `uploaded`;
- não usar somente `isThisDeviceRecording` do servidor para afirmar que está gravando;
- mostrar estado real local.

## `useRecSession.js`

- fila persistente;
- retry com backoff;
- não parar heartbeat no primeiro erro;
- corrigir eventos fora de ordem;
- não apagar clips em `Object.assign`;
- recuperar após reload;
- polling de SAVEs;
- dedupe persistente.

## `RecSessionService.php`

- remover read-modify-write do array completo em cache;
- corrigir limpeza dos expirados;
- lease atômica por posição;
- garantir exclusividade;
- validar proprietário da sessão.

## `RecController.php`

- Form Requests;
- policies;
- tokens de sessão;
- idempotência;
- transações;
- FFmpeg fora da request;
- não responder sucesso silencioso quando o broadcast falhar sem fallback;
- criar targets;
- validar se a câmera fazia parte do SAVE;
- não confiar em `recorder_id`.

## `RecClipNormalizeService.php`

- não apagar arquivo original antes de confirmar substituição;
- separar bruto, temporário e final;
- validar ffprobe;
- retornar objetos de resultado claros;
- usar jobs;
- capturar códigos de falha;
- limitar stderr salvo;
- tratar ausência de áudio;
- tratar orientação;
- não executar `ffmpeg -version` a cada clip;
- criar health check/cache para disponibilidade do FFmpeg.

---

# CACHE DA DISPONIBILIDADE DO FFMPEG

Não execute:

```text
ffmpeg -version
```

para cada processamento.

Implemente:

- health check inicial;
- cache com TTL;
- comando de diagnóstico;
- falha clara quando indisponível;
- métrica;
- readiness check do worker.

---

# CONCORRÊNCIA

Cubra explicitamente:

1. dois celulares tentando A1;
2. dois heartbeats simultâneos;
3. stop e heartbeat simultâneos;
4. SAVE e expiração da sessão simultâneos;
5. dois uploads do mesmo segmento;
6. dois jobs do mesmo target;
7. dois SAVEs sobrepostos;
8. clip pronto antes do evento de SAVE no frontend;
9. retry após timeout quando o servidor já persistiu;
10. limpeza concorrendo com pin de segmento.

Use:

- índices únicos;
- locks curtos;
- transações;
- compare-and-set;
- idempotency keys;
- jobs únicos quando adequado.

Não use locks longos durante FFmpeg.

---

# RECONCILIAÇÃO

Crie job ou command periódico para encontrar inconsistências:

- segmento no storage sem banco;
- banco sem arquivo;
- target pronto sem clip;
- clip pronto sem arquivo;
- sessão ativa sem lease;
- lease sem sessão ativa;
- SAVE preso em processing;
- job falho;
- segmentos que deveriam estar pinned;
- contadores divergentes.

Comando sugerido:

```text
php artisan rec:reconcile
```

Adicionar modo:

```text
--dry-run
--fix
--game=
--save=
```

---

# COMANDOS OPERACIONAIS

Crie comandos equivalentes a:

```text
php artisan rec:health
php artisan rec:reconcile --dry-run
php artisan rec:cleanup
php artisan rec:expire-sessions
php artisan rec:retry-failed
php artisan rec:inspect-save {uuid}
php artisan rec:inspect-session {uuid}
```

Cada comando deve retornar saída útil e código de saída correto.

---

# TESTES AUTOMATIZADOS

Use o framework de testes já adotado.

## Backend unitários

Testar:

- cálculo da janela;
- seleção de segmentos;
- time sync;
- status agregados;
- retry classification;
- caminhos de storage;
- checksum;
- merge de estados;
- transitions.

## Backend feature

Testar:

- registro da câmera;
- exclusividade por posição;
- heartbeat com token;
- heartbeat de outra sessão;
- stop idempotente;
- SAVE sem câmeras;
- SAVE com escopo left;
- SAVE com escopo right;
- SAVE all;
- SAVEs consecutivos;
- SAVEs sobrepostos;
- criação dos targets;
- upload válido;
- upload duplicado;
- upload com checksum inválido;
- upload de outra partida;
- upload de posição fora do target;
- sessão expirada;
- polling de SAVEs;
- ACK idempotente;
- URLs privadas;
- autorização.

## Concorrência

Criar testes ou scripts para:

- duas aquisições simultâneas de A1;
- dois inserts do mesmo segmento;
- dois jobs do mesmo target;
- SAVE concorrendo com heartbeat;
- limpeza concorrendo com pin.

## Jobs

Testar:

- preview pronto;
- final pronto;
- FFmpeg falhando;
- ffprobe inválido;
- arquivo ausente;
- retry;
- raw preservado;
- status atualizado;
- evento publicado.

## Frontend unitários

Testar:

- IndexedDB repository;
- recuperação após reload;
- fila persistente;
- backoff;
- eventos fora de ordem;
- dedupe;
- heartbeat;
- polling;
- track encerrada;
- falta de áudio;
- quota;
- dois SAVEs rápidos;
- SAVE antes de 25 segundos;
- segmento atual preservado.

## E2E

Use Playwright, Cypress ou a ferramenta existente.

Cenários:

1. quatro câmeras registradas;
2. SAVE all;
3. SAVE left;
4. SAVE right;
5. dois SAVEs com intervalo de 1 segundo;
6. offline por 30 segundos;
7. reload durante upload;
8. WebSocket desligado;
9. câmera sem áudio;
10. posição ocupada;
11. FFmpeg atrasado;
12. uma câmera offline;
13. retorno da câmera;
14. preview parcial;
15. finalização posterior.

## Teste de carga

Crie script para simular:

- quatro câmeras;
- upload contínuo;
- múltiplas partidas;
- SAVEs simultâneos;
- fila FFmpeg;
- limite de CPU;
- S3;
- Redis.

Meça:

- tempo até criação do SAVE;
- tempo até primeiro preview;
- tempo até todos os clips;
- taxa de falha;
- consumo de banda;
- CPU;
- memória;
- tamanho da fila;
- espaço em storage.

---

# TESTES MANUAIS OBRIGATÓRIOS

Documente e execute, quando o ambiente permitir:

1. SAVE após 2 segundos.
2. SAVE após 5 segundos.
3. SAVE após 10 segundos.
4. SAVE após 20 segundos.
5. SAVE após 30 segundos.
6. dois SAVEs separados por 1 segundo.
7. dois SAVEs separados por 3 segundos.
8. dez SAVEs seguidos.
9. internet desligada por 30 segundos.
10. internet desligada por 2 minutos.
11. reload durante upload.
12. navegador fechado e reaberto.
13. WebSocket indisponível.
14. Redis reiniciado.
15. worker reiniciado.
16. FFmpeg indisponível.
17. disco temporário cheio.
18. S3 indisponível.
19. microfone negado.
20. câmera negada.
21. tela bloqueada.
22. aplicativo em background.
23. modo economia de bateria.
24. bateria baixa.
25. duas câmeras em A1.
26. câmera trocando de rede Wi-Fi para 4G.
27. upload respondendo após timeout.
28. resposta duplicada.
29. eventos fora de ordem.
30. target parcial.
31. segmento corrompido.
32. checksum divergente.
33. arquivo final inválido.
34. job repetido.
35. limpeza durante SAVE.

---

# CRITÉRIOS DE ACEITE

A implementação só está concluída quando:

1. Nenhum SAVE depende exclusivamente de WebSocket.
2. Segmentos sobrevivem a reload.
3. Uploads retomam automaticamente.
4. SAVEs consecutivos funcionam.
5. SAVE com menos de 25 segundos salva o disponível.
6. Duas câmeras não ocupam a mesma posição.
7. Heartbeat se recupera de falha transitória.
8. Upload duplicado não cria clip duplicado.
9. FFmpeg não roda dentro da requisição HTTP.
10. Arquivo bruto é preservado até validação.
11. Cada câmera possui target próprio.
12. A interface mostra estados por câmera.
13. Polling recupera evento perdido.
14. Jobs possuem retry e failed state.
15. Há índices únicos no banco.
16. Há autorização por partida e sessão.
17. URLs de vídeo são privadas.
18. Existe preview rápido.
19. Existe versão final validada.
20. Existem métricas e logs estruturados.
21. Existem comandos de diagnóstico.
22. Existem testes automatizados.
23. Build frontend passa.
24. Testes backend passam.
25. Lint passa.
26. Migrations sobem e descem com segurança.
27. Existe plano de rollback.
28. Existe documentação de implantação.
29. O código antigo pode ser desativado por feature flag.
30. O módulo pode ser ativado gradualmente.

---

# META DE DESEMPENHO

Use estas metas iniciais e torne-as configuráveis:

```text
criação da solicitação SAVE: p95 menor que 300 ms
ACK das câmeras online: p95 menor que 2 s
primeiro preview disponível: p95 menor que 8 s
todos os previews de quatro câmeras: p95 menor que 15 s
erro silencioso: zero
upload duplicado gerando registro duplicado: zero
perda após reload com dados já persistidos localmente: zero
```

Meça em ambiente real e registre resultados.

Se uma meta não for possível com a infraestrutura atual, documente o gargalo com evidência e implemente a melhor alternativa possível.

---

# ESTRATÉGIA DE MIGRAÇÃO

Implemente em fases.

## Fase 0 — Baseline

- inventário;
- testes da implementação atual;
- logs;
- métricas;
- feature flags;
- snapshot do schema;
- documentação do fluxo atual.

## Fase 1 — Correções críticas

- bugs de buffer;
- SAVE abaixo de 25 segundos;
- heartbeat resiliente;
- dedupe;
- índices únicos;
- eventos fora de ordem;
- proteção do arquivo original;
- FFmpeg em queue.

## Fase 2 — Sessões e lease

- `rec_recorder_sessions`;
- token;
- Redis por posição;
- recuperação;
- health state.

## Fase 3 — IndexedDB

- segmentos locais;
- fila persistente;
- retry;
- reload;
- quota.

## Fase 4 — Segmentos contínuos

- `rec_segments`;
- upload contínuo;
- retenção;
- time sync;
- checksum.

## Fase 5 — SAVE por targets

- `rec_save_targets`;
- pin dos segmentos;
- ACK;
- polling;
- pós-roll;
- recuperação.

## Fase 6 — Preview e final

- jobs;
- preview;
- final;
- storage privado;
- URLs assinadas.

## Fase 7 — Hardening

- observabilidade;
- reconciliação;
- carga;
- E2E;
- rollout gradual.

Cada fase deve manter o sistema funcional.

---

# ROLLOUT

Use feature flags por:

- ambiente;
- usuário administrador;
- partida;
- percentual;
- dispositivo.

Estratégia:

1. ambiente local;
2. QA com uma câmera;
3. QA com quatro câmeras;
4. partida de teste;
5. shadow mode;
6. produção com uma partida;
7. expansão gradual;
8. desativação do legado após estabilidade.

## Shadow mode

Quando possível:

- manter fluxo atual;
- enviar segmentos no novo fluxo;
- comparar resultados;
- não expor novo fluxo ao usuário;
- registrar divergências.

---

# ROLLBACK

Documente:

- como voltar para endpoints antigos;
- como pausar workers;
- como preservar arquivos;
- como reverter migrations sem perder dados;
- como liberar leases;
- como reprocessar SAVEs;
- como recuperar fila.

Não crie rollback que apague dados de vídeo.

---

# DOCUMENTAÇÃO A ENTREGAR

Crie arquivos em `docs/rec/`:

```text
architecture.md
current-flow.md
target-flow.md
database.md
api.md
frontend.md
indexeddb.md
redis-leases.md
storage.md
ffmpeg.md
queues.md
observability.md
testing.md
deployment.md
rollback.md
incident-response.md
troubleshooting.md
```

Inclua diagramas Mermaid para:

- registro de câmera;
- heartbeat;
- upload contínuo;
- SAVE;
- recuperação de WebSocket;
- recuperação offline;
- processamento;
- limpeza;
- rollback.

---

# INCIDENT RESPONSE

Documente procedimentos para:

- câmera aparecendo online sem gravar;
- segmentos parados;
- fila local crescendo;
- Redis indisponível;
- S3 indisponível;
- FFmpeg indisponível;
- jobs acumulados;
- SAVE parcial;
- arquivo corrompido;
- URL inválida;
- perda de lease;
- storage cheio;
- checksum divergente.

Para cada incidente, informar:

```text
sintoma
impacto
como detectar
consulta SQL
chaves Redis
logs
métricas
ação imediata
recuperação
prevenção
```

---

# ENTREGA DO CURSOR

Ao terminar, apresente:

1. resumo das mudanças;
2. arquivos criados;
3. arquivos alterados;
4. migrations;
5. novos endpoints;
6. novos eventos;
7. novos jobs;
8. novas configurações;
9. testes adicionados;
10. comandos executados;
11. resultados dos testes;
12. pendências;
13. riscos;
14. instruções de deploy;
15. instruções de rollback;
16. checklist de teste em quatro celulares.

Não diga apenas que implementou. Mostre evidências.

---

# ORDEM DE TRABALHO OBRIGATÓRIA

Execute nesta ordem:

1. Inspecionar o repositório.
2. Mapear fluxo atual.
3. Identificar versões e infraestrutura.
4. Criar documentação baseline.
5. Criar feature flags.
6. Criar testes que reproduzem os bugs atuais.
7. Corrigir bugs críticos.
8. Criar migrations.
9. Implementar sessões e leases.
10. Implementar autenticação da câmera.
11. Implementar IndexedDB.
12. Implementar fila persistente.
13. Implementar segmentos contínuos.
14. Implementar time sync.
15. Implementar targets.
16. Implementar polling e ACK.
17. Mover FFmpeg para queue.
18. Implementar preview e final.
19. Implementar storage privado.
20. Reestruturar interface.
21. Adicionar observabilidade.
22. Adicionar reconciliação.
23. Adicionar testes de concorrência.
24. Adicionar E2E.
25. Executar teste de carga.
26. Documentar deploy.
27. Documentar rollback.
28. Entregar relatório final.

Não pule etapas silenciosamente.

---

# DECISÕES TÉCNICAS IMPORTANTES

## SAVEs consecutivos

Não use cooldown global.

Use:

- debounce local curto;
- idempotency key;
- SAVEs sobrepostos;
- compartilhamento dos mesmos segmentos entre múltiplos SAVEs;
- pin por relacionamento, sem duplicar o arquivo físico.

## Compartilhamento de segmentos

Um mesmo segmento pode pertencer a vários SAVEs.

Não copie o arquivo físico sem necessidade.

Crie relação adequada entre SAVE target e segmentos, por exemplo:

```text
rec_save_target_segments
```

Campos:

```text
rec_save_target_id
rec_segment_id
order
overlap_from_ms
overlap_until_ms
created_at
```

Índice único:

```text
rec_save_target_id + rec_segment_id
```

## Pós-roll

Permitir `REC_POST_ROLL_SECONDS=0` ou outro valor.

## Partial success

Um SAVE com três câmeras prontas e uma offline deve ser `partial`, não totalmente `failed`.

## Primeiro preview

O frontend deve mostrar a primeira câmera pronta sem aguardar as outras.

## Consistência eventual

A interface precisa tolerar:

- eventos duplicados;
- eventos atrasados;
- polling;
- respostas antigas;
- atualização parcial.

---

# CUIDADOS COM NAVEGADORES

Faça detecção de capabilities:

- `MediaRecorder`;
- MIME suportado;
- `IndexedDB`;
- `navigator.storage`;
- Wake Lock;
- Network Information;
- Battery API;
- Screen Orientation;
- Fullscreen;
- `crypto.subtle`;
- `BroadcastChannel`;
- service worker;
- Background Sync.

Não trate APIs opcionais como obrigatórias.

Para iPhone/Safari:

- testar MIME disponível;
- `playsinline`;
- restrições de fullscreen;
- restrições de background;
- armazenamento;
- interrupções do sistema;
- fallback claro.

Não prometer gravação em background quando o navegador não garante.

---

# SERVICE WORKER

Se o projeto já for PWA, avalie:

- Background Sync para uploads;
- mensagens entre página e service worker;
- não tentar acessar MediaRecorder no service worker;
- apenas processar filas já persistidas;
- segurança dos tokens;
- limite de tempo do navegador.

Se não houver suporte, a página deve retomar a fila ao abrir.

---

# BANCO E COMPATIBILIDADE

O usuário já exige compatibilidade entre bancos em outras partes do projeto.

Evite recursos específicos sem fallback.

Use tipos, índices e queries compatíveis com o banco real.

Caso o ambiente seja MySQL:

- evitar depender de índice parcial;
- usar campos e constraints adequados;
- revisar tamanho de índices;
- armazenar UUID conforme padrão existente.

---

# QUALIDADE DO CÓDIGO

- métodos pequenos;
- responsabilidades claras;
- nomes explícitos;
- enums quando suportados;
- casts;
- DTOs;
- policies;
- Form Requests;
- transações;
- jobs idempotentes;
- exceptions de domínio;
- responses consistentes;
- sem duplicação de constantes;
- sem lógica pesada em Vue component;
- sem lógica pesada em controller;
- sem suppress indiscriminado de erros;
- sem `@unlink` ou `@rename` escondendo falha crítica;
- sem `catch` vazio em operações críticas;
- sem `console.log` como única telemetria.

---

# SAÍDA ESPERADA DESTA TAREFA

Faça as alterações diretamente no repositório.

Não entregue apenas sugestões ou pseudocódigo.

Quando encontrar uma decisão dependente da infraestrutura atual:

1. inspecione o projeto;
2. escolha a alternativa compatível;
3. documente a decisão;
4. implemente;
5. teste.

Quando não puder concluir algo por ausência real de infraestrutura externa, deixe:

- código preparado;
- feature flag desativada;
- documentação exata;
- teste local;
- lista objetiva do que falta configurar.

Comece agora pelo inventário do repositório e pelo mapeamento do fluxo atual.
