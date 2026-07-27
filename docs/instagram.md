# Implementação completa da integração automática com Instagram no QNF

## Papel

Atue como engenheiro de software sênior responsável por analisar e implementar esta funcionalidade no projeto QNF existente.

Você tem acesso total ao código. Antes de alterar qualquer arquivo, inspecione a arquitetura, os models, migrations, services, jobs, events, listeners, observers, controllers, requests, resources, policies, testes, telas Vue/Inertia, fluxo de sorteio, fluxo de draft, registro de resultado, cálculo de pontuação, geração dos times da semana, armazenamento de arquivos, filas, scheduler e padrões já adotados.

Não entregue apenas um plano. Implemente a funcionalidade completa, execute os testes e corrija os problemas encontrados.

## Objetivo

Criar uma integração robusta, segura, idempotente, observável e desacoplada com a API oficial do Instagram para publicar automaticamente conteúdos do QNF.

A integração deve permitir:

1. Publicar um Story quando o draft for finalizado.
2. Quando o administrador registrar e confirmar o resultado de uma partida:
   - aguardar a conclusão da regra existente de pontuação;
   - identificar os times que pontuaram pela regra oficial já existente no sistema;
   - gerar os times da semana usando o fluxo existente;
   - publicar os times da semana em um carrossel no feed;
   - publicar uma sequência de Stories em vídeo;
   - inserir áudio nos vídeos;
   - marcar no Instagram os jogadores dos times que receberam pontos.
3. Manter histórico, status, erros e possibilidade segura de reprocessamento.
4. Evitar publicações duplicadas mesmo com retries, timeouts, jobs repetidos, edição do resultado ou execução concorrente.

## Regras obrigatórias

- Não replique nem reimplemente a regra de pontuação.
- Localize a fonte autoritativa existente que define vitória, empate duplo e pontuação.
- Consuma o resultado final dessa regra.
- Não calcule pontos novamente dentro da integração do Instagram.
- Não publique dentro de Controller, Observer ou transação de banco.
- Dispare eventos de domínio somente após commit.
- Toda comunicação com a Meta deve ocorrer por jobs de fila.
- Uma falha no Instagram nunca pode impedir que o resultado, draft, pontuação ou qualquer regra principal do QNF seja salva.
- Não exponha tokens, App Secret ou dados sensíveis no frontend, logs, exceptions, Telescope, responses ou banco sem criptografia.
- Não altere funcionalidades não relacionadas.
- Não deixe código morto, arquivos incompletos, TODOs ou mocks em produção.
- Não adicione comentários redundantes ao código.
- Respeite os padrões e convenções existentes no projeto.

---

# 1. Auditoria inicial obrigatória

Antes de implementar, encontre e documente internamente:

- versão do Laravel e PHP;
- stack do frontend e padrão dos formulários;
- model responsável por partidas;
- model responsável por times sorteados;
- relacionamento entre time, jogador e usuário;
- como o capitão é identificado;
- onde o draft muda para o estado finalizado;
- onde o administrador confirma o resultado;
- onde a pontuação é calculada e persistida;
- como o sistema identifica vitória, derrota, empate e empate duplo;
- onde e quando são gerados os times da semana;
- se já existem Events, Listeners, Jobs ou Services nesses fluxos;
- se já existe geração de imagem compartilhável;
- se já existe preferência de música para jogador/capitão;
- storage utilizado em desenvolvimento e produção;
- configuração atual de queue, scheduler e failed jobs;
- padrões de DTO, enum, action, service, repository e testes existentes.

Use essas descobertas para integrar a funcionalidade nos pontos corretos. Não crie um fluxo paralelo ao domínio existente.

---

# 2. Instagram do usuário

## Banco de dados

Adicione ao usuário um campo específico e sem ambiguidade:

```text
instagram_username
```

Não use um campo genérico chamado `instagram` se o projeto não possuir uma convenção que justifique isso.

Requisitos:

- nullable;
- armazenar apenas o username, sem `@`;
- remover espaços;
- aceitar entrada como `@christian.steffens_`, `christian.steffens_` ou URL completa do perfil;
- normalizar para `christian.steffens_`;
- converter para lowercase;
- validar caracteres permitidos;
- impedir duplicidade entre usuários locais, salvo se a arquitetura existente justificar o contrário;
- manter compatibilidade com usuários antigos;
- adicionar ao model, request, resource e telas aplicáveis;
- permitir edição pelo próprio usuário ou pelo administrador conforme as policies existentes;
- exibir uma prévia da URL final do perfil no formulário.

Crie uma classe dedicada para normalização e validação, por exemplo:

```text
InstagramUsernameNormalizer
```

Não espalhe regex e normalização por Controllers ou componentes.

## Música do capitão

Primeiro verifique se já existe no projeto algum campo, relacionamento ou configuração de música do jogador/capitão.

- Se existir, reutilize-o.
- Se não existir, implemente a estrutura mínima necessária, preferencialmente um campo nullable no usuário, como `instagram_story_audio_path`, acompanhado do formulário apropriado.
- O arquivo deve ser validado e armazenado com segurança.
- Aceite apenas formatos que possam ser normalizados para AAC durante o processamento.
- Defina uma música padrão pelo sistema de configuração.

Política de seleção:

1. música configurada para o capitão do time;
2. música padrão configurada para o Instagram do QNF;
3. se nenhuma estiver disponível, publicar o vídeo sem áudio, sem falhar a publicação inteira.

Não dependa de música da biblioteca do Instagram. O áudio deve ser incorporado no arquivo final antes do upload.

---

# 3. Configuração da integração

Inspecione as variáveis já existentes no `.env` e reutilize os nomes atuais quando forem claros. Não renomeie chaves sem necessidade.

Centralize a configuração em `config/services.php` ou em um arquivo de configuração dedicado.

A configuração deve contemplar, de acordo com o que já existe:

```text
INSTAGRAM_APP_ID
INSTAGRAM_APP_SECRET
INSTAGRAM_ACCESS_TOKEN
INSTAGRAM_USER_ID
INSTAGRAM_GRAPH_VERSION
INSTAGRAM_GRAPH_BASE_URL
INSTAGRAM_DEFAULT_STORY_AUDIO_PATH
INSTAGRAM_ENABLED
INSTAGRAM_DRY_RUN
```

Requisitos:

- `INSTAGRAM_ENABLED=false` deve desabilitar os disparos sem quebrar o domínio.
- `INSTAGRAM_DRY_RUN=true` deve executar geração e validação dos assets sem chamar a API.
- valide configuração obrigatória antes de enfileirar publicações reais;
- nunca acesse `env()` fora dos arquivos de configuração;
- nunca envie segredo ou token para Vue/Inertia.

## Token

A integração precisa suportar token de longa duração e renovação.

Não tente escrever no arquivo `.env` em runtime.

Implemente uma solução segura para armazenar o token renovado:

- crie uma entidade/configuração persistente para a conta do Instagram;
- salve token com cast criptografado;
- salve `instagram_user_id`, username, expiração, última renovação e status;
- permita usar o token do `.env` apenas como bootstrap inicial;
- depois do bootstrap, prefira o token criptografado persistido;
- crie comando para importar ou atualizar as credenciais iniciais;
- crie job/comando agendado para renovar o token antes do vencimento;
- não renove token expirado como se ainda fosse válido;
- gere alerta claro quando for necessária nova autenticação manual.

Sugestões de comandos:

```text
php artisan instagram:bootstrap-account
php artisan instagram:test-connection
php artisan instagram:refresh-token
```

---

# 4. Arquitetura esperada

Adapte os nomes à arquitetura existente, mantendo responsabilidades separadas.

Estrutura conceitual esperada:

```text
app/Instagram/
├── Contracts/
│   ├── InstagramClient.php
│   ├── InstagramAssetRenderer.php
│   └── InstagramPublicationRepository.php
├── Data/
│   ├── InstagramPublicationData.php
│   ├── InstagramMediaData.php
│   ├── InstagramTagData.php
│   └── InstagramContainerData.php
├── Enums/
│   ├── InstagramPublicationType.php
│   ├── InstagramPublicationStatus.php
│   ├── InstagramMediaType.php
│   └── InstagramTriggerType.php
├── Exceptions/
├── Services/
│   ├── InstagramApiClient.php
│   ├── InstagramPublishingService.php
│   ├── InstagramContainerService.php
│   ├── InstagramTokenService.php
│   ├── InstagramTagService.php
│   ├── InstagramAssetService.php
│   ├── InstagramMediaValidator.php
│   ├── InstagramStoryVideoService.php
│   └── InstagramMusicResolver.php
└── Support/
```

Não crie abstrações inúteis. Caso o projeto já possua outra organização consolidada, siga-a, preservando a separação de responsabilidades.

## Cliente HTTP

Use o cliente HTTP nativo do Laravel e implemente:

- timeout de conexão;
- timeout total;
- retry somente para falhas transitórias;
- parsing consistente dos erros da Meta;
- exception própria contendo código, subcódigo, tipo e mensagem sanitizada;
- logs sem token;
- versionamento da Graph API por configuração;
- User-Agent identificando o QNF;
- correlation ID interno por publicação.

Não use chamadas `curl` espalhadas pelo código.

---

# 5. Persistência e idempotência

Crie uma estrutura persistente para acompanhar todas as publicações.

## `instagram_accounts`

Campos mínimos sugeridos:

```text
id
instagram_user_id
username
access_token
access_token_expires_at
last_refreshed_at
status
last_error
created_at
updated_at
```

Use cast criptografado para `access_token`.

## `instagram_publications`

Campos mínimos sugeridos:

```text
id
uuid
instagram_account_id
trigger_type
trigger_id
trigger_version
publication_type
status
idempotency_key
payload
metadata
instagram_container_id
instagram_media_id
permalink
attempts
last_error_code
last_error_message
queued_at
processing_started_at
published_at
failed_at
created_at
updated_at
```

Requisitos:

- `idempotency_key` deve possuir índice unique;
- `payload` e `metadata` devem usar JSON/casts;
- não salve token no payload;
- mantenha snapshot suficiente para reproduzir o conteúdo mesmo que os dados principais mudem depois;
- use relacionamento polimórfico para o trigger se combinar com os padrões do projeto;
- se a aplicação utiliza UUIDs como padrão, siga o padrão existente.

## `instagram_publication_items`

Use uma tabela separada se o carrossel e os Stories precisarem de itens independentes:

```text
id
instagram_publication_id
position
media_type
local_path
public_url
instagram_container_id
status
metadata
last_error
created_at
updated_at
```

## Idempotência

A chave deve considerar, no mínimo:

```text
trigger_type + trigger_id + trigger_version + publication_type
```

Exemplos:

```text
draft-finalized:123:v1:story
match-result:456:v3:weekly-teams-carousel
match-result:456:v3:weekly-team-story:team-12
```

Use lock de banco ou cache distribuído ao preparar e publicar, evitando concorrência.

Nunca crie outro container ou outro post apenas porque houve timeout sem antes reconciliar o estado da publicação existente.

---

# 6. Eventos e gatilhos do domínio

## Draft finalizado

Localize o momento real em que o draft passa para o estado finalizado.

Depois do commit, dispare ou reutilize um evento de domínio equivalente a:

```text
DraftFinalized
```

O listener deve apenas criar o registro idempotente e despachar o job. Não deve renderizar mídia nem chamar a Meta de forma síncrona.

Conteúdo do Story de draft:

- identidade visual do QNF;
- título claro indicando draft finalizado;
- data/rodada/partida;
- times, cores e jogadores definidos no draft;
- layout vertical 1080x1920;
- nomes legíveis em dispositivos móveis;
- logo sem distorção;
- respeitar safe areas do Story;
- usar imagem JPEG ou vídeo conforme o renderer existente e a melhor solução encontrada no projeto.

A publicação do draft não deve recalcular pontos e não deve marcar jogadores como pontuadores, pois o resultado ainda não existe.

## Resultado confirmado pelo administrador

Localize a operação autoritativa que confirma o resultado.

O fluxo deve ser:

1. validar e persistir o resultado;
2. executar a regra existente de pontuação;
3. persistir os pontos;
4. gerar ou atualizar os times da semana pelo fluxo existente;
5. concluir a transação;
6. depois do commit, disparar um evento de domínio equivalente a `MatchResultFinalized` ou `WeeklyTeamsReady`;
7. criar publicações idempotentes independentes para feed e Stories.

Não dependa de uma sequência frágil de Observers.

Se já houver Events adequados, reutilize-os.

## Alteração posterior do resultado

Trate edição/correção de resultado explicitamente:

- não publique duplicado para a mesma versão;
- mantenha uma versão ou hash estável do resultado relevante;
- se uma correção gerar nova versão, não apague o histórico anterior;
- não publique silenciosamente múltiplas correções sem controle;
- implemente uma ação administrativa ou comando de reprocessamento quando necessário;
- documente o comportamento final adotado.

---

# 7. Identificação dos jogadores que pontuaram

A fonte deve ser a pontuação já calculada pelo domínio.

Considere como pontuadores os jogadores pertencentes aos times que efetivamente receberam pontos no resultado processado, incluindo:

- vitória;
- empate duplo;
- qualquer outra situação que a regra existente já reconheça como pontuação.

Não codifique `if` duplicando essas regras dentro do módulo do Instagram.

Crie um serviço que receba o snapshot final da pontuação e retorne os usuários marcáveis:

```text
ScoringInstagramUsersResolver
```

Esse serviço deve:

- receber os times pontuadores já definidos pelo domínio;
- obter seus jogadores/usuários;
- ignorar usuário sem `instagram_username`;
- normalizar novamente por segurança;
- remover duplicados;
- não incluir o próprio `qnfporto`;
- respeitar limites da API;
- registrar quais usuários foram incluídos, ignorados ou rejeitados.

Não crie uma publicação adicional apenas porque houve pontuação. As marcações devem fazer parte das publicações disparadas pela confirmação do resultado.

---

# 8. Marcação no Instagram

Implemente marcação real pela API, não apenas texto desenhado na imagem.

## Feed

- Use `user_tags` nos itens compatíveis.
- Em carrossel, aplique as marcações no item correspondente ao time do jogador.
- Use coordenadas normalizadas e distribuídas em posições seguras.
- Não sobreponha todas as marcações no mesmo ponto.
- Também mencione os usernames na legenda quando isso melhorar a confiabilidade e não ultrapassar os limites permitidos.

## Stories

- Use o suporte atual de `user_tags` para Stories de imagem ou vídeo.
- Aplique ao Story específico do time que pontuou.
- Inclua visualmente os usernames no layout como fallback de comunicação, sem confundir isso com a marcação interativa da API.

## Resiliência das marcações

Um username inválido, privado, inexistente, alterado ou indisponível não pode cancelar toda a publicação.

Implemente:

1. validação e normalização local;
2. tentativa com as marcações válidas;
3. tratamento específico de erro da Meta;
4. fallback controlado removendo marcações problemáticas;
5. publicação do conteúdo mesmo que alguma marcação falhe;
6. persistência e log dos usernames não marcados;
7. nenhuma repetição infinita.

Respeite o limite atual de menções/tags por mídia. Se o número exceder o limite:

- priorize jogadores dos times pontuadores;
- distribua entre Stories específicos por time;
- não trunque silenciosamente;
- registre no metadata a estratégia aplicada.

---

# 9. Conteúdo dos times da semana

## Carrossel no feed

Publique um carrossel com identidade visual consistente.

Estrutura sugerida:

1. capa: “Times da semana”;
2. um card por time;
3. card final opcional com resumo da pontuação.

Cada card de time deve exibir:

- nome/cor do time;
- jogadores;
- capitão;
- pontuação obtida;
- rodada ou data de referência;
- logo QNF;
- layout otimizado para feed em 4:5, preferencialmente 1080x1350;
- tipografia e contraste adequados;
- mesma proporção em todos os itens do carrossel.

O carrossel deve mostrar explicitamente, com os valores reais:

```text
Time Verde: 3 pontos
Time Amarelo: 2 pontos
Time Azul: 2 pontos
```

Não fixe nomes, cores ou pontos. Use os dados reais.

A legenda deve ser montada por uma classe dedicada e conter:

- título;
- rodada/data;
- resumo dos times e pontuação;
- menções dos jogadores pontuadores quando aplicável;
- texto dentro dos limites da API;
- hashtags configuráveis, sem excesso.

A API aceita no máximo 10 itens em um carrossel. Se o conteúdo exceder isso, implemente uma estratégia determinística, como agrupar informações ou gerar mais de uma publicação, mantendo idempotência.

## Stories dos times da semana

Crie uma sequência de Stories, preferencialmente um vídeo por time, para permitir que cada time utilize a música do próprio capitão.

Cada Story deve:

- ter 1080x1920;
- apresentar um time;
- mostrar jogadores, capitão e pontos;
- marcar os jogadores daquele time quando o time tiver pontuado;
- usar música do capitão ou música padrão;
- possuir duração configurável entre 3 e 60 segundos;
- usar transições discretas e legibilidade mobile;
- não ultrapassar 100 MB.

Caso o desenho existente exija um vídeo consolidado com todos os times, implemente uma política determinística para o áudio e documente-a. A preferência é um Story por time.

---

# 10. Geração de imagens

Antes de adicionar uma biblioteca, verifique se o projeto já utiliza alguma solução de renderização, como SVG, Canvas, Browsershot, Playwright, Imagick, Intervention Image ou FFmpeg.

Reutilize a solução existente quando ela atender aos requisitos.

Requisitos para imagens de Story:

```text
Formato: JPEG
Tamanho máximo: 8 MB
Proporção recomendada: 9:16
Resolução recomendada: 1080x1920
Espaço de cor: sRGB
```

Requisitos para imagens de feed:

```text
Formato: JPEG
Tamanho máximo: 8 MB
Proporção: entre 4:5 e 1,91:1
Largura mínima: 320 px
Largura máxima: 1440 px
Resolução recomendada do carrossel: 1080x1350
Espaço de cor: sRGB
```

Implemente validação real antes do upload:

- MIME real;
- extensão;
- dimensões;
- proporção;
- tamanho;
- espaço de cor quando a biblioteca permitir;
- arquivo legível e não corrompido.

Converta para JPEG/sRGB quando necessário.

---

# 11. Geração dos vídeos de Story

Use FFmpeg por meio de `Laravel\Process`, Symfony Process ou abstração segura equivalente. Não monte comandos em shell concatenando entrada do usuário.

O serviço deve receber DTOs e construir argumentos individualmente.

## Especificações obrigatórias de Story

```text
Contêiner: MOV ou MP4, preferencialmente MP4
Sem edit lists
Atom moov no início do arquivo
Áudio: AAC
Taxa de amostragem do áudio: no máximo 48 kHz
Canais: mono ou estéreo
Vídeo: H.264 ou HEVC, preferencialmente H.264
Scan: progressivo
GOP: fechado
Chroma subsampling: 4:2:0
FPS: entre 23 e 60
Resolução recomendada: 1080x1920
Proporção recomendada: 9:16
Bitrate de vídeo: VBR, no máximo 25 Mbps
Bitrate de áudio: no máximo 128 kbps
Duração: entre 3 e 60 segundos
Tamanho máximo: 100 MB
Pixel format recomendado: yuv420p
```

Produza por padrão:

```text
H.264
AAC
1080x1920
30 FPS
AAC 48 kHz estéreo
128 kbps de áudio
faststart
bitrate de vídeo conservador
```

Aplique normalização de volume para evitar áudio excessivamente alto ou baixo.

Se o áudio for menor que o vídeo, faça loop de forma segura. Se for maior, corte no final do vídeo.

Exemplo conceitual, que deve ser adaptado e executado sem shell injection:

```bash
ffmpeg \
  -loop 1 \
  -i imagem.jpg \
  -stream_loop -1 \
  -i musica.mp3 \
  -vf "scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,format=yuv420p" \
  -c:v libx264 \
  -preset medium \
  -crf 22 \
  -r 30 \
  -g 60 \
  -sc_threshold 0 \
  -c:a aac \
  -ar 48000 \
  -ac 2 \
  -b:a 128k \
  -af "loudnorm=I=-16:LRA=11:TP=-1.5" \
  -t 15 \
  -shortest \
  -pix_fmt yuv420p \
  -movflags +faststart \
  story.mp4
```

Não copie esse comando cegamente. Gere o comando de acordo com a mídia de entrada e valide o resultado.

---

# 12. Validação com FFprobe

Crie um validador que execute `ffprobe` e leia JSON.

Valide antes de enviar para o Instagram:

- contêiner;
- codec de vídeo;
- codec de áudio;
- duração;
- tamanho em bytes;
- largura e altura;
- proporção;
- FPS;
- pixel format;
- sample rate;
- canais;
- bitrate de áudio e vídeo quando disponível;
- presença do stream esperado;
- arquivo não corrompido.

Se o asset estiver fora do padrão:

1. tente uma transcodificação controlada;
2. valide novamente;
3. se continuar inválido, marque a publicação como falha antes de chamar a Meta;
4. registre diagnóstico legível sem dados sensíveis.

Crie testes unitários para as regras do validador e um teste de integração condicionado à presença de FFmpeg/FFprobe.

---

# 13. Armazenamento e URLs públicas

A Meta precisa baixar os arquivos por URL pública HTTPS.

Implemente uma área dedicada, por exemplo:

```text
instagram/publications/{publication_uuid}/
```

Requisitos:

- nomes imprevisíveis com UUID;
- HTTPS;
- sem autenticação para o arquivo específico;
- Content-Type correto;
- URL estável durante todo o processamento da Meta;
- não usar URL local;
- não usar URL temporária que expire antes da publicação;
- não expor diretórios ou listagem;
- limpar assets somente depois da publicação e de um período de segurança;
- manter arquivos de falhas por período configurável para diagnóstico;
- job agendado para limpeza.

Se usar URLs assinadas, a validade deve cobrir com folga renderização, upload, polling, retries e download pela Meta.

---

# 14. Fluxo de publicação na API

## Imagem ou Story

1. criar registro idempotente;
2. gerar asset;
3. validar asset;
4. disponibilizar URL pública;
5. criar media container;
6. persistir `container_id` imediatamente;
7. consultar o status do container;
8. publicar somente quando `status_code=FINISHED`;
9. persistir `media_id` e status final;
10. consultar e persistir permalink quando disponível;
11. limpar o asset após o período de segurança.

## Vídeo

Não chame `media_publish` imediatamente após criar o container.

Implemente polling assíncrono por jobs com backoff. Não bloqueie worker com loops longos ou `sleep` extensos.

Estados possíveis devem ser tratados explicitamente, incluindo processamento, finalizado, erro e expiração.

## Carrossel

1. gerar e validar todos os itens;
2. criar um child container para cada item com `is_carousel_item=true`;
3. persistir cada child container;
4. aguardar todos ficarem prontos;
5. criar o container pai `CAROUSEL` com os children na ordem correta;
6. aguardar o pai ficar pronto;
7. publicar uma única vez;
8. persistir o resultado.

Se um child falhar, não publique um carrossel incompleto silenciosamente.

## Containers e reconciliação

- containers não publicados expiram;
- não reutilize container expirado;
- implemente reconciliação antes de refazer uma chamada após timeout;
- se houver incerteza sobre o resultado de `media_publish`, não crie imediatamente outra publicação;
- consulte o estado disponível e faça reconciliação para evitar duplicação;
- mantenha um job periódico para publicações presas em `processing` ou `publishing`.

---

# 15. Jobs e filas

Separe os estágios para facilitar retry e observabilidade.

Sugestão conceitual:

```text
PrepareInstagramPublicationJob
RenderInstagramAssetsJob
ValidateInstagramAssetsJob
CreateInstagramContainersJob
CheckInstagramContainersJob
PublishInstagramMediaJob
ReconcileInstagramPublicationJob
RefreshInstagramTokenJob
CleanupInstagramAssetsJob
```

Adapte para não criar jobs artificiais demais.

Requisitos:

- `ShouldQueue`;
- queue dedicada, se a infraestrutura permitir;
- `afterCommit`;
- timeout adequado para FFmpeg;
- retries com backoff progressivo;
- `failed()` persistindo o estado;
- locks por publicação;
- não serializar models enormes ou relações desnecessárias;
- preferir IDs e snapshots/DTOs estáveis;
- jobs idempotentes;
- não fazer `sleep` longo dentro do worker;
- separar falha de Story e falha de feed para que uma não bloqueie a outra.

Backoff sugerido para falhas transitórias:

```text
30s, 2min, 5min, 15min, 30min
```

Ajuste conforme os padrões existentes e o tipo de erro.

Não tente novamente automaticamente erros permanentes como token inválido, permissão ausente, formato inválido ou configuração incompleta.

---

# 16. Limites e proteção contra abuso

Implemente proteção local para:

- limite de publicações da API em janela móvel;
- limite de itens do carrossel;
- limite de menções;
- tamanho da legenda;
- número de hashtags;
- duração e tamanho dos vídeos;
- containers expirados;
- chamadas concorrentes;
- retries excessivos.

Antes de publicar, consulte o endpoint de limite de publicação quando necessário e adie o job caso a conta esteja próxima do limite.

Não transforme falha de limite em perda de publicação. Reagende com status explícito.

---

# 17. Observabilidade

Crie logs estruturados em canal dedicado ou padrão equivalente existente.

Cada log deve conter quando aplicável:

```text
publication_uuid
trigger_type
trigger_id
publication_type
instagram_container_id
instagram_media_id
status
attempt
error_code
error_subcode
```

Nunca registre:

```text
access_token
app_secret
URL contendo token
payload sensível completo
```

Implemente uma visualização administrativa simples, se o projeto já possui área administrativa adequada, contendo:

- tipo;
- gatilho;
- status;
- data;
- tentativas;
- erro sanitizado;
- link publicado;
- ação de reprocessar quando seguro.

Proteja a ação de retry por policy/autorização.

Sugestões de comandos:

```text
php artisan instagram:test-connection
php artisan instagram:publish-test --type=story --dry-run
php artisan instagram:retry-failed {publication?}
php artisan instagram:reconcile
php artisan instagram:cleanup-assets
```

---

# 18. Segurança

- Use token apenas no backend.
- Use cast criptografado no banco.
- Nunca retorne token em Resource ou response.
- Nunca envie token em query string de logs.
- Oculte credenciais em exceptions.
- Valide paths de música e mídia.
- Não aceite path arbitrário enviado pelo cliente.
- Evite path traversal.
- Evite shell injection no FFmpeg.
- Limite tamanho e MIME dos uploads de música.
- Use policies existentes.
- Não torne storage inteiro público; publique apenas os assets necessários.
- Documente rotação/reconexão do token.

---

# 19. Testes obrigatórios

Use o framework de testes já adotado.

## Unitários

Crie testes para:

- normalização de username com `@`;
- normalização de URL completa;
- rejeição de username inválido;
- remoção de duplicados;
- resolução dos usuários dos times pontuadores;
- confirmação de que a regra de pontos não foi duplicada;
- seleção da música do capitão;
- fallback para música padrão;
- fallback sem áudio;
- construção da legenda;
- limites de tags;
- posições das tags;
- idempotency key;
- validação das especificações de imagem;
- validação das especificações de vídeo;
- tratamento dos erros da Meta;
- classificação entre erro transitório e permanente.

## Feature/integração

Crie testes para:

- draft finalizado despacha uma única publicação depois do commit;
- draft não finalizado não publica;
- resultado confirmado dispara fluxo somente depois da pontuação;
- vitória inclui jogadores do time vencedor;
- empate duplo inclui os jogadores dos times que realmente pontuaram conforme o domínio;
- usuário sem Instagram é ignorado;
- job repetido não duplica publicação;
- execução concorrente não duplica publicação;
- edição de resultado segue a política de versionamento definida;
- falha do Instagram não desfaz resultado/pontuação;
- `INSTAGRAM_ENABLED=false` não chama a API;
- dry-run gera e valida assets sem publicar;
- criação de container de imagem;
- polling de vídeo até `FINISHED`;
- falha do container;
- expiração do container;
- fluxo completo de carrossel;
- retry de falha transitória;
- ausência de retry em falha permanente;
- marcação inválida não cancela toda a publicação;
- token não aparece em logs.

Use `Http::fake()` com sequências realistas de respostas da Meta.

## Regressão

Execute toda a suíte existente e garanta que sorteio, draft, registro de resultado, pontuação, pagamentos e demais módulos não foram alterados indevidamente.

---

# 20. Qualidade visual

Não gere cards apenas funcionais. Mantenha qualidade visual compatível com uma publicação real do QNF.

Requisitos:

- logo em alta resolução;
- cores oficiais dos times;
- tipografia consistente;
- alinhamento e espaçamento padronizados;
- contraste adequado;
- conteúdo centralizado e legível;
- safe area para Stories;
- cards do carrossel com mesma estrutura;
- não esticar fotos ou logo;
- suportar nomes longos sem quebrar o layout;
- suportar quantidades variáveis de jogadores;
- snapshot tests ou testes do payload visual quando a arquitetura permitir.

Use templates reutilizáveis e dados separados da apresentação.

---

# 21. Documentação

Crie documentação interna, por exemplo:

```text
docs/instagram-integration.md
```

Inclua:

- visão geral da arquitetura;
- gatilhos;
- variáveis de ambiente;
- comando de bootstrap;
- configuração do token;
- renovação;
- requisitos de FFmpeg/FFprobe;
- filas necessárias;
- scheduler;
- storage público;
- como testar sem publicar;
- como diagnosticar falhas;
- como reprocessar;
- como alterar música padrão;
- como desconectar a integração;
- limitações da API;
- checklist de deploy.

Atualize `.env.example` sem inserir valores reais.

---

# 22. Scheduler e deploy

Registre no scheduler, conforme a versão do Laravel:

- renovação preventiva do token;
- reconciliação de publicações presas;
- limpeza de assets;
- retry controlado de publicações reagendadas.

Garanta que produção possua:

- FFmpeg;
- FFprobe;
- worker de fila;
- scheduler;
- storage configurado;
- HTTPS;
- domínio público acessível pela Meta.

Forneça os comandos exatos de instalação, migration, cache e restart dos workers conforme o ambiente encontrado no projeto.

---

# 23. Critérios de aceite

A implementação estará concluída somente quando:

1. O usuário puder cadastrar seu username do Instagram de forma validada e normalizada.
2. O draft finalizado gerar exatamente uma publicação de Story.
3. O resultado confirmado gerar o carrossel e os Stories sem duplicidade.
4. Os pontos exibidos forem exatamente os calculados pelo domínio existente.
5. Os jogadores dos times pontuadores forem marcados quando possuírem username válido.
6. Um username inválido não impedir a publicação dos demais conteúdos.
7. O carrossel mostrar claramente a pontuação de cada time.
8. Os Stories em vídeo utilizarem música do capitão, música padrão ou fallback sem áudio.
9. Todo vídeo for validado por FFprobe antes do envio.
10. Nenhum Story ultrapassar 60 segundos ou 100 MB.
11. Nenhuma imagem ultrapassar 8 MB.
12. O serviço aguardar o processamento dos containers antes de publicar.
13. Retries não gerarem posts duplicados.
14. O token estiver protegido e renovável.
15. Houver logs e histórico suficientes para diagnóstico.
16. Existirem testes automatizados para fluxos principais e falhas.
17. Toda a suíte existente continuar passando.
18. Não houver secrets em código, logs ou commits.

---

# 24. Entrega final esperada

Ao terminar:

1. liste todos os arquivos criados e alterados;
2. explique resumidamente as decisões arquiteturais;
3. informe migrations e comandos necessários;
4. informe configuração de queue e scheduler;
5. informe dependências do sistema operacional;
6. mostre como executar um dry-run;
7. mostre como executar uma publicação de teste;
8. mostre como reprocessar uma falha;
9. apresente os testes executados e seus resultados;
10. informe qualquer limitação real encontrada no código ou na API.

Não omita arquivos. Não entregue pseudocódigo. Implemente arquivos completos e consistentes com o projeto.
