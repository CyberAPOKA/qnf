# Prompt — Comandos do WhatsApp para gerenciamento da partida QNF

Implemente no projeto QNF um sistema de comandos enviados pelo grupo do WhatsApp para permitir que jogadores entrem/desistam da partida e que administradores adicionem/removam jogadores.

## Contexto

O projeto já possui integração não oficial com WhatsApp através de um serviço separado, acessado pelo Laravel via `WhatsAppService.php` e configurado em `services.whatsapp.url`.

Analise primeiro toda a implementação existente do serviço WhatsApp e toda a regra atual de partidas, inscrições de jogadores, lista principal, fila de espera, desistência, promoção do próximo jogador da fila e identificação de administradores.

Não recrie regras de negócio que já existem. Reutilize Services, Actions, Models, Enums, Events, Jobs e demais estruturas existentes sempre que possível. O comportamento executado por um comando do WhatsApp deve ser o mesmo comportamento já executado pela aplicação web.

## Objetivo

Quando uma mensagem for enviada no grupo configurado do QNF, o serviço do WhatsApp deve identificar comandos suportados e encaminhá-los de forma segura para o Laravel.

O Laravel deve identificar quem enviou a mensagem através do número de telefone e executar a ação correspondente.

Mensagens comuns do grupo que não sejam comandos suportados devem ser ignoradas.

## Comandos de jogador

### `/play` e `/jogar`

Os dois comandos são aliases e devem executar exatamente a mesma ação.

Ao receber um deles:

1. Identificar o jogador pelo número de telefone do remetente.
2. Identificar a partida/rodada aplicável ao grupo conforme as regras existentes do QNF.
3. Verificar se o jogador pode entrar na partida.
4. Executar exatamente as regras existentes de entrada na partida.
5. Se houver vaga, adicionar o jogador normalmente.
6. Se as vagas estiverem preenchidas e a regra atual permitir fila de espera, adicionar o jogador à fila.
7. Respeitar todas as validações existentes, como jogador já inscrito, rodada indisponível, posição, limites, status da partida e demais regras existentes.
8. Responder no WhatsApp informando o resultado da operação.

Não duplique no módulo do WhatsApp a lógica responsável por decidir entre lista principal e fila de espera.

### `/desistir` e `/quit`

Os dois comandos são aliases e devem executar exatamente a mesma ação.

Ao receber um deles:

1. Identificar o jogador pelo número de telefone.
2. Verificar sua participação na partida/rodada atual.
3. Se estiver na lista da partida, executar a desistência utilizando a regra existente.
4. Se estiver na fila de espera, removê-lo da fila.
5. Quando a saída gerar uma vaga, executar a regra existente para promover o próximo jogador da fila de espera.
6. Respeitar integralmente a ordem e as regras atuais da fila.
7. Responder no WhatsApp informando o resultado.

Não implemente manualmente uma segunda regra para escolher o próximo da fila se o projeto já possuir essa lógica.

### `/commands` e `/comandos`

Os dois comandos são aliases e devem retornar uma mensagem explicando os comandos disponíveis para aquele usuário.

Para jogadores comuns, listar pelo menos:

- `/jogar` ou `/play` — entrar na partida ou na fila de espera.
- `/desistir` ou `/quit` — desistir da partida ou sair da fila de espera.
- `/comandos` ou `/commands` — mostrar os comandos disponíveis.

Para administradores, incluir também os comandos administrativos.

## Rate limit / anti-spam

Todos os comandos devem possuir proteção contra spam.

A identificação do jogador para rate limit deve ser feita pelo número de telefone do remetente.

Use preferencialmente `RateLimiter`, Cache/Redis ou outra infraestrutura já existente no projeto. Não crie controle em memória local do processo.

Crie chaves de rate limit previsíveis e isoladas por comando/usuário quando aplicável.

### Comandos comuns

`/play`, `/jogar`, `/desistir` e `/quit` devem possuir cooldown individual por jogador.

Defina um intervalo curto e razoável para impedir spam e deixe esse valor centralizado/configurável, evitando magic numbers espalhados pelo código.

Aliases da mesma ação devem compartilhar o mesmo rate limit. Portanto:

- `/play` e `/jogar` pertencem ao mesmo bucket.
- `/desistir` e `/quit` pertencem ao mesmo bucket.

### `/commands` e `/comandos`

Este comando possui uma regra diferente: o cooldown é GLOBAL para jogadores comuns.

Depois que qualquer jogador comum utilizar `/commands` ou `/comandos`, nenhum outro jogador comum poderá utilizar esse comando durante 1 hora.

Os aliases devem compartilhar o mesmo cooldown global.

O administrador não está sujeito a esse cooldown e pode executar `/commands` ou `/comandos` a qualquer momento.

Quando um comando for bloqueado pelo rate limit, retornar uma mensagem curta apropriada e não executar nenhuma regra de negócio.

## Comandos administrativos

Somente administradores reconhecidos pelas regras existentes do QNF podem executar estes comandos.

Não confie em informações enviadas no payload dizendo que alguém é administrador. Resolva o remetente pelo telefone e valide sua autorização no backend.

Administradores podem utilizar os comandos administrativos sem o cooldown aplicado aos jogadores comuns.

### `/add {number}`

Permitir ao administrador adicionar manualmente um jogador à partida atual.

Exemplos:

```text
/add 51999999999
/add +55 51 99999-9999
```

Se a biblioteca utilizada pela integração permitir recuperar corretamente menções do WhatsApp, suportar também:

```text
/add @usuario
```

Nesse caso, não tente extrair o telefone apenas do texto exibido da menção. Utilize os metadados/IDs reais da mensagem fornecidos pela biblioteca do WhatsApp para resolver o participante mencionado.

O comando deve:

1. Resolver o telefone informado ou mencionado.
2. Localizar o jogador correspondente no QNF.
3. Executar a mesma regra de entrada utilizada pelo `/play`/`/jogar` e pela aplicação.
4. Respeitar as regras existentes da partida, inclusive lista principal/fila de espera.
5. Informar ao administrador o resultado.

Não permitir que `/add` ignore regras importantes da partida apenas por ser um comando administrativo, salvo se o sistema atual já possuir explicitamente uma operação administrativa com esse comportamento.

### `/remove {number}`

Permitir ao administrador remover um jogador da partida atual ou da fila de espera.

Exemplos:

```text
/remove 51999999999
/remove +55 51 99999-9999
```

Também suportar menção quando tecnicamente possível:

```text
/remove @usuario
```

O comando deve:

1. Resolver o jogador pelo telefone ou metadados da menção.
2. Removê-lo da partida ou fila utilizando as regras existentes.
3. Caso seja aberta uma vaga, executar a promoção do próximo jogador da fila exatamente conforme a regra atual do sistema.
4. Informar o resultado ao administrador.

## Identificação pelo telefone

Centralize a normalização dos números de telefone.

Considere formatos como:

```text
+55 51 99999-9999
5551999999999
51999999999
```

Analise como os telefones são armazenados atualmente no QNF e implemente a comparação compatível com o padrão existente.

Não altere indiscriminadamente números armazenados no banco sem necessidade.

Caso o telefone recebido não corresponda a nenhum jogador, retornar uma mensagem apropriada sem executar a ação.

## Mensagens em grupo

A integração deve diferenciar:

- ID do grupo/chat;
- telefone/ID do autor real da mensagem;
- conteúdo textual;
- menções da mensagem, quando disponíveis.

Em mensagens de grupo, não considere o ID do grupo como sendo o telefone do jogador.

Somente processe comandos originados do grupo configurado para o QNF, salvo se a implementação atual possuir uma regra mais específica que deva ser preservada.

Não processe como novos comandos mensagens enviadas pelo próprio bot/cliente conectado, evitando loops.

## Segurança do endpoint Laravel

O endpoint usado pelo serviço WhatsApp para encaminhar mensagens ao Laravel não pode ficar publicamente utilizável sem autenticação.

Implemente autenticação entre o serviço WhatsApp e Laravel utilizando um segredo/token configurado por variável de ambiente.

O backend deve rejeitar requisições inválidas antes de executar qualquer comando.

Não exponha tokens em logs.

## Arquitetura

Mantenha responsabilidades separadas.

Não coloque toda a implementação dentro de `WhatsAppService.php`, Controller ou arquivo principal do serviço Node.

Crie uma estrutura organizada para:

- recebimento do evento do WhatsApp;
- autenticação do webhook interno;
- DTO/payload da mensagem;
- normalização do telefone;
- parser/roteador de comandos;
- comandos de jogador;
- comandos administrativos;
- rate limiting;
- respostas do WhatsApp.

O `WhatsAppService.php` deve continuar focado na comunicação de saída com o serviço WhatsApp, podendo ser estendido somente quando fizer sentido arquiteturalmente.

Reutilize a arquitetura e os padrões já existentes no projeto antes de criar novas abstrações.

## Concorrência e idempotência

Considere que dois jogadores podem enviar `/jogar` praticamente ao mesmo tempo.

A execução não pode permitir ultrapassar limites da partida, duplicar inscrições ou promover incorretamente jogadores da fila.

Reutilize transactions, locks e proteções existentes. Caso a regra atual não esteja protegida contra concorrência, implemente a proteção na camada correta da regra de negócio, e não somente no controller do WhatsApp.

Evite processar duas vezes o mesmo evento recebido do WhatsApp quando a biblioteca disponibilizar um identificador único da mensagem. Utilize esse ID para idempotência quando possível.

## Respostas esperadas

As mensagens devem ser curtas e adequadas para WhatsApp.

Exemplos de situações que precisam de resposta:

- jogador entrou na partida;
- jogador entrou na fila de espera;
- jogador já estava inscrito;
- jogador desistiu;
- jogador saiu da fila;
- jogador não está participando;
- partida não está disponível;
- telefone não corresponde a jogador cadastrado;
- comando em cooldown;
- administrador adicionou jogador;
- administrador removeu jogador;
- usuário sem permissão para comando administrativo;
- número informado inválido;
- jogador informado não encontrado.

Não espalhe textos de resposta pela implementação. Centralize-os ou siga o padrão de tradução/mensagens já existente no projeto.

## Testes

Adicione testes automatizados cobrindo pelo menos:

- `/play`;
- `/jogar`;
- entrada na fila;
- jogador já inscrito;
- `/desistir`;
- `/quit`;
- remoção da fila;
- promoção do próximo jogador;
- `/commands`;
- `/comandos`;
- cooldown individual;
- aliases compartilhando cooldown;
- cooldown global de 1 hora de `/commands`/`/comandos`;
- bypass do cooldown global pelo administrador;
- `/add` por telefone;
- `/remove` por telefone;
- `/add` por menção, se suportado pela biblioteca;
- `/remove` por menção, se suportado pela biblioteca;
- usuário comum tentando executar comando administrativo;
- telefone desconhecido;
- payload não autenticado;
- mensagem originada de outro grupo;
- mensagem comum que não é comando;
- prevenção de processamento duplicado da mesma mensagem;
- cenários concorrentes relevantes de entrada/fila.

Use o framework de testes e os padrões já existentes no projeto.

## Requisitos finais

Antes de alterar código:

1. Analise a implementação completa atual da integração WhatsApp.
2. Identifique qual biblioteca/serviço está conectado ao WhatsApp Web.
3. Analise as regras existentes de partida, entrada, desistência e fila de espera.
4. Identifique como jogadores são relacionados aos números de telefone.
5. Identifique como administradores são definidos atualmente.
6. Reutilize as regras existentes em vez de duplicá-las.

Depois implemente a funcionalidade completa, incluindo alterações necessárias tanto no serviço responsável pelo WhatsApp quanto no Laravel.

Mantenha o código seguindo os padrões atuais do projeto, com responsabilidades pequenas, tipagem adequada, nomes claros e sem código morto.

Não faça alterações desnecessárias fora do escopo.
