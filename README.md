# Futsal Draft (Laravel + Jetstream Inertia SSR)

Sistema semanal (quarta 18:00) para inscrição de 15 jogadores, sorteio de capitães e draft em tempo real, com mensagem final para WhatsApp.

## Requisitos

- PHP 8.2+
- Composer
- Node 18+
- Banco MyAQL
- Extensões PHP comuns do Laravel (pdo, mbstring, openssl, tokenizer, xml, ctype, json)

## Setup do zero (local)

### 1) Instalar dependências

```bash
composer install
npm install
```

```bash
php artisan migrate
php artisan db:seed
npm run build

php artisan serve
npm run dev
php artisan reverb:start
php artisan queue:work
php artisan schedule:work

php artisan migrate:fresh --seed
php artisan test

# Abrir o jogo 
php artisan futsal:open-week-game {--force}

CTRL+SHIFT+P->User Settings
"claudeCode.initialPermissionMode": "bypassPermissions"
```