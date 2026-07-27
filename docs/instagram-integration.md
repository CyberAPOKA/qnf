# Integração Instagram — QNF

## Visão geral

A integração publica automaticamente conteúdos do QNF via Instagram Graph API:

| Gatilho | Conteúdo |
|---------|----------|
| Draft finalizado (`DraftService` após commit) | 1 Story (imagem 1080×1920) |
| Resultado confirmado + times da semana gerados (`GenerateWeekTeamImageJob`) | Carrossel no feed (4:5) + Stories em vídeo por time vencedor |

Toda comunicação com a Meta ocorre em jobs de fila. Falhas no Instagram **nunca** revertem draft, placar ou pontuação.

## Arquitetura

```
app/Instagram/
  Enums/ Data/ Exceptions/ Support/
  Services/   # API, token, assets, render, publish
  Jobs/       # Process, Refresh, Cleanup, Reconcile
app/Models/InstagramAccount|Publication|PublicationItem
```

Idempotência: `idempotency_key = trigger_type:trigger_id:trigger_version:publication_type[:suffix]`.

Versão do resultado = hash SHA-256 dos placares. Correção de placar gera nova versão sem apagar histórico.

## Variáveis de ambiente

```env
INSTAGRAM_ENABLED=false
INSTAGRAM_DRY_RUN=false
INSTAGRAM_APP_ID=
INSTAGRAM_APP_SECRET=
INSTAGRAM_ACCESS_TOKEN=
INSTAGRAM_USER_ID=
INSTAGRAM_GRAPH_VERSION=v22.0
INSTAGRAM_GRAPH_BASE_URL=https://graph.instagram.com
INSTAGRAM_DEFAULT_STORY_AUDIO_PATH=
INSTAGRAM_OWN_USERNAME=qnfporto
INSTAGRAM_QUEUE=default
INSTAGRAM_STORY_DURATION_SECONDS=15
INSTAGRAM_CAPTION_HASHTAGS=#qnf,#qnfporto,#futebol
```

Nunca envie token/secret ao frontend. Token renovado fica em `instagram_accounts.access_token` (cast `encrypted`).

## Bootstrap e token

```bash
php artisan migrate
php artisan instagram:bootstrap-account
php artisan instagram:test-connection
php artisan instagram:refresh-token
```

- `.env` serve só como bootstrap inicial.
- Depois, o token persistido no banco tem prioridade.
- Token já expirado **não** é renovado automaticamente → status `needs_reauth` (nova autenticação manual).
- Scheduler: `instagram:refresh-token` diário.

## Música nos Stories

A API **não** permite sticker de música do catálogo. O áudio é embutido no MP4:

1. MP3 do capitão (`music_file_path`)
2. `INSTAGRAM_DEFAULT_STORY_AUDIO_PATH`
3. Vídeo sem áudio (não falha a publicação)

YouTube sozinho não vira arquivo de áudio.

## Username do jogador

Campo `users.instagram_username` (único, nullable), normalizado por `InstagramUsernameNormalizer`.

- Perfil: `/profile` → formulário Instagram
- Admin: formulário de jogador

Marcações usam `game_players.points > 0` (não recalculam pontuação).

## Filas e scheduler

- Queue: `INSTAGRAM_QUEUE` (default `default`)
- Worker: `php artisan queue:work`
- Scheduler (`routes/console.php`):
  - `instagram:refresh-token` — daily
  - `instagram:reconcile` — every 5 minutes
  - `instagram:cleanup-assets` — daily 03:30

## Storage / HTTPS

Assets em `storage/app/public/instagram/publications/{uuid}/`.

A Meta baixa por URL pública HTTPS. Em produção configure `APP_URL` ou `STORAGE_FALLBACK_URL` acessível pela Meta.

## Dry-run e testes

```bash
# Gera/valida assets sem chamar a API
INSTAGRAM_ENABLED=true INSTAGRAM_DRY_RUN=true

php artisan instagram:publish-test --type=story --dry-run --game=ID
php artisan instagram:publish-test --type=carousel --dry-run --game=ID
```

Publicação real (requer HTTPS público + token válido):

```bash
INSTAGRAM_ENABLED=true
INSTAGRAM_DRY_RUN=false
php artisan instagram:bootstrap-account
php artisan instagram:publish-test --type=story --game=ID
```

## Reprocessar falhas

```bash
php artisan instagram:retry-failed
php artisan instagram:retry-failed {uuid}
```

UI admin: `/admin/instagram` (retry por publicação).

## Dependências de SO

- FFmpeg
- FFprobe
- Extensão PHP GD
- Worker de fila + scheduler (`php artisan schedule:work` ou cron)
- Storage link: `php artisan storage:link`

## Checklist de deploy

1. Variáveis Instagram no `.env`
2. `php artisan migrate`
3. `php artisan instagram:bootstrap-account`
4. `php artisan instagram:test-connection`
5. `INSTAGRAM_ENABLED=true` (comece com `DRY_RUN=true`)
6. FFmpeg/FFprobe no PATH
7. Queue worker + scheduler
8. URL pública HTTPS dos assets
9. Música padrão opcional em storage público

## Limitações da API

- Sem sticker de música / enquetes / links em Stories via API
- Carrossel: máx. 10 itens
- ~25–100 publicações / 24h (consultar limit endpoint)
- Imagens ≤ 8 MB; Stories vídeo ≤ 60s / 100 MB
- Containers não publicados expiram
- Username inválido/privado: publicação segue sem essa marcação

## Diagnóstico

- Logs: `storage/logs/instagram.log` (canal `instagram`) e `laravel.log`
- Tabela `instagram_publications` (status, attempts, last_error_*)
- `php artisan instagram:reconcile` para jobs presos
- Nunca logar `access_token` / app secret
