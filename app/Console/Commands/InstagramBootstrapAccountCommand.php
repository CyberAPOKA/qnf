<?php

namespace App\Console\Commands;

use App\Instagram\Services\InstagramTokenService;
use Illuminate\Console\Command;
use Throwable;

class InstagramBootstrapAccountCommand extends Command
{
    protected $signature = 'instagram:bootstrap-account';

    protected $description = 'Importa ou atualiza a conta Instagram a partir das variáveis de ambiente';

    public function handle(InstagramTokenService $tokenService): int
    {
        try {
            $account = $tokenService->bootstrapFromEnv();
        } catch (Throwable $e) {
            $this->error('Falha ao fazer bootstrap da conta Instagram: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Conta Instagram pronta.');
        $this->line('Username: '.($account->username ?: '(desconhecido)'));
        $this->line('User ID: '.$account->instagram_user_id);
        $this->line('Status: '.($account->status?->value ?? 'n/a'));

        return self::SUCCESS;
    }
}
