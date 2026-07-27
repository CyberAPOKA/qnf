<?php

namespace App\Console\Commands;

use App\Instagram\Services\InstagramApiClient;
use App\Instagram\Services\InstagramTokenService;
use Illuminate\Console\Command;
use Throwable;

class InstagramTestConnectionCommand extends Command
{
    protected $signature = 'instagram:test-connection';

    protected $description = 'Testa a conexão com a API do Instagram Graph';

    public function handle(InstagramTokenService $tokenService, InstagramApiClient $apiClient): int
    {
        try {
            $account = $tokenService->resolveAccount();
            $me = $apiClient->getMe($tokenService->accessToken());
        } catch (Throwable $e) {
            $this->error('Falha na conexão com o Instagram: '.$e->getMessage());

            return self::FAILURE;
        }

        $userId = (string) ($me['user_id'] ?? $me['id'] ?? $account->instagram_user_id);
        $username = (string) ($me['username'] ?? $account->username ?? '');

        $this->info('Conexão OK.');
        $this->line('user_id: '.$userId);
        $this->line('username: '.$username);

        return self::SUCCESS;
    }
}
