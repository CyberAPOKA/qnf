# REC — Operações e manutenção

Módulo de gravação contínua de vídeos durante jogos. Vários celulares gravam; qualquer um pode acionar **SAVE REC** para capturar os últimos ~30s de todas as câmeras ativas.

## Onde os vídeos são salvos

Os clips são gravados no disco **`public`** do Laravel:

```
storage/app/public/rec/{game_id}/{save_request_uuid}/arquivo.webm
```

**Exemplo:**

```
storage/app/public/rec/49/8f0f0a11-626c-426b-82c3-dbe575dc7382/afiR8I5Rqq3G3aZ0UZ72aVbxrUNfUAmxPoBtMQLa.webm
```

Na web, o acesso é via symlink `public/storage` → `storage/app/public`:

```
https://qnf.com.br/storage/rec/49/.../arquivo.webm
```

Registros no banco:

| Tabela              | Conteúdo                          |
|---------------------|-----------------------------------|
| `rec_save_requests` | Cada SAVE REC acionado            |
| `rec_clips`         | Clips enviados (path, duração…)   |

---

## Servidor de produção

Caminho do projeto (ajuste se necessário):

```bash
cd /var/www/qnf
```

### Listar arquivos

Todos os RECs:

```bash
ls -lah storage/app/public/rec/
```

Um jogo específico:

```bash
ls -lah storage/app/public/rec/49/
```

Todos os `.webm` com tamanho e data:

```bash
find storage/app/public/rec -type f -name "*.webm" -printf "%T+ %s bytes %p\n" | sort
```

Espaço ocupado:

```bash
du -sh storage/app/public/rec/
```

Localizar a pasta `rec` se não souber o caminho do projeto:

```bash
sudo find /var/www -path "*/storage/app/public/rec" -type d 2>/dev/null
```

---

## Limpar todos os vídeos de teste

Apagar **somente** os arquivos deixa links quebrados na interface. Para reset completo, limpe **arquivos + banco**.

### 1. Apagar arquivos

Confira antes:

```bash
cd /var/www/qnf
ls -lah storage/app/public/rec/
du -sh storage/app/public/rec/
```

Apague a pasta `rec` (não apague `storage/app/public` inteira):

```bash
sudo rm -rf storage/app/public/rec
sudo mkdir -p storage/app/public/rec
sudo chown deploy:www-data storage/app/public/rec
sudo chmod 775 storage/app/public/rec
```

Alternativa — esvaziar mantendo a pasta:

```bash
sudo -u www-data rm -rf storage/app/public/rec/*
```

### 2. Limpar banco de dados

Via Artisan:

```bash
php artisan tinker --execute="DB::table('rec_clips')->truncate(); DB::table('rec_save_requests')->truncate();"
```

Ou dentro do tinker:

```bash
php artisan tinker
```

```php
DB::table('rec_clips')->truncate();
DB::table('rec_save_requests')->truncate();
exit
```

Ou direto no MySQL:

```sql
TRUNCATE TABLE rec_clips;
TRUNCATE TABLE rec_save_requests;
```

### 3. Conferir

```bash
ls -lah storage/app/public/rec/
php artisan tinker --execute="echo App\Models\RecClip::count();"
```

Deve retornar pasta vazia e contagem `0`.

### Limpar REC de um jogo só

Exemplo: jogo `50`:

```bash
sudo rm -rf storage/app/public/rec/50
```

```bash
php artisan tinker --execute="DB::table('rec_clips')->where('game_id', 50)->delete(); DB::table('rec_save_requests')->where('game_id', 50)->delete();"
```

---

## Erro: Permission denied ao apagar

```
rm: cannot remove 'storage/app/public/rec/.../merged_....webm': Permission denied
```

**Causa:** os arquivos foram criados pelo PHP-FPM (geralmente `www-data`), e o usuário `deploy` não tem permissão para removê-los.

**Verificar dono:**

```bash
ls -la storage/app/public/rec/50/
ls -la storage/app/public/rec/
```

**Solução:** usar `sudo` ou apagar como `www-data`:

```bash
sudo rm -rf storage/app/public/rec
sudo mkdir -p storage/app/public/rec
sudo chown deploy:www-data storage/app/public/rec
sudo chmod 775 storage/app/public/rec
```

Ou:

```bash
sudo -u www-data rm -rf storage/app/public/rec/*
```

**Permissões recomendadas** (evitar o problema no futuro):

```bash
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

---

## Backup antes de apagar

```bash
cd /var/www/qnf
tar -czf rec-backup-$(date +%F).tar.gz storage/app/public/rec/
```

---

## Deploy / build

Alterações no frontend exigem rebuild:

```bash
npm run build
# ou
npx vite build
```

Alterações em PHP (ex.: `RecClipNormalizeService.php`) só precisam de deploy do código — sem rebuild do frontend.

---

## Logs úteis (produção)

```bash
tail -f storage/logs/laravel.log | grep REC
```

Eventos comuns:

| Log                         | Significado                    |
|-----------------------------|--------------------------------|
| `REC start`                 | Gravação iniciada              |
| `REC stop`                  | Gravação parada                |
| `REC save requested`        | SAVE REC acionado              |
| `REC merge ok`              | Merge prefix+current ok        |
| `REC normalize ok`          | FFmpeg normalizou o clip      |
| `REC upload ok`             | Clip salvo com sucesso         |
| `REC merge failed`          | Falha no merge de segmentos    |
| `REC normalize skipped`     | FFmpeg indisponível            |

---

## Requisitos de produção

- **FFmpeg** instalado no servidor (normalização e merge dos clips)
- **PHP-FPM** com upload ≥ 64M (`upload_max_filesize`, `post_max_size`)
- **Nginx** com `client_max_body_size 64M`
- **Reverb** rodando (Supervisor) para clips em tempo real
- **Queue worker** ativo para jobs de upload/normalize

---

## Arquivos principais do módulo

| Arquivo | Função |
|---------|--------|
| `app/Http/Controllers/RecController.php` | API: start/stop/save/upload |
| `app/Services/RecSessionService.php` | Sessão de gravadores ativos |
| `app/Services/RecClipNormalizeService.php` | FFmpeg: merge e normalize |
| `resources/js/Pages/Rec.vue` | Interface mobile |
| `resources/js/composables/useRecBuffer.js` | Buffer circular no celular |
| `resources/js/composables/useRecSession.js` | Upload + Echo |
