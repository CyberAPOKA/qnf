<?php

namespace App\Console\Commands;

use App\Instagram\Enums\InstagramAccountStatus;
use App\Instagram\Services\InstagramTokenService;
use Illuminate\Console\Command;
use Throwable;

class InstagramRefreshTokenCommand extends Command
{
    protected $signature = 'instagram:refresh-token {--force : Força a renovação mesmo se o token ainda não estiver perto de expirar}';

    protected $description = 'Renova o token de acesso do Instagram quando necessário';

    public function handle(InstagramTokenService $tokenService): int
    {
        $force = (bool) $this->option('force');

        try {
            $before = $tokenService->resolveAccount();
            $refreshedAtBefore = $before->last_refreshed_at?->toIso8601String();

            $account = $tokenService->refreshIfNeeded($force);
        } catch (Throwable $e) {
            $this->error('Falha ao renovar o token: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $account) {
            $this->warn('Nenhuma conta Instagram resolvida.');

            return self::FAILURE;
        }

        if ($account->status === InstagramAccountStatus::NeedsReauth) {
            $this->error('Token exige reautenticação manual.');
            $this->line('Status: '.$account->status->value);
            if ($account->last_error) {
                $this->line('Erro: '.$account->last_error);
            }

            return self::FAILURE;
        }

        $refreshedAtAfter = $account->last_refreshed_at?->toIso8601String();
        $didRefresh = $refreshedAtBefore !== $refreshedAtAfter;

        if (! $didRefresh) {
            $this->info($force
                ? 'Nenhuma renovação aplicada.'
                : 'Token ainda válido; renovação não necessária.');
            $this->line('Username: '.($account->username ?: '(desconhecido)'));
            $this->line('Expira em: '.($account->access_token_expires_at?->toDateTimeString() ?: '(desconhecido)'));

            return self::SUCCESS;
        }

        $this->info($force ? 'Token renovado com --force.' : 'Token renovado com sucesso.');
        $this->line('Username: '.($account->username ?: '(desconhecido)'));
        $this->line('Status: '.$account->status?->value);
        $this->line('Expira em: '.($account->access_token_expires_at?->toDateTimeString() ?: '(desconhecido)'));
        $this->line('Última renovação: '.($account->last_refreshed_at?->toDateTimeString() ?: '(nunca)'));

        return self::SUCCESS;
    }
}
