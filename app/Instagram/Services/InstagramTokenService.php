<?php

namespace App\Instagram\Services;

use App\Instagram\Enums\InstagramAccountStatus;
use App\Instagram\Exceptions\InstagramApiException;
use App\Instagram\Exceptions\InstagramConfigurationException;
use App\Models\InstagramAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstagramTokenService
{
    private ?InstagramAccount $resolved = null;

    public function __construct(
        private readonly InstagramApiClient $apiClient,
    ) {}

    public function bootstrapFromEnv(): InstagramAccount
    {
        $token = (string) config('instagram.access_token');
        $userId = (string) config('instagram.user_id');

        if ($token === '' || $userId === '') {
            throw new InstagramConfigurationException(
                'INSTAGRAM_ACCESS_TOKEN and INSTAGRAM_USER_ID are required to bootstrap the account.'
            );
        }

        $username = null;

        try {
            $me = $this->apiClient->getMe($token);
            $username = isset($me['username']) ? (string) $me['username'] : null;
            $remoteUserId = (string) ($me['user_id'] ?? $me['id'] ?? '');

            if ($remoteUserId !== '' && $remoteUserId !== $userId) {
                Log::warning('Instagram bootstrap user_id differs from config', [
                    'configured_user_id' => $userId,
                    'remote_user_id' => $remoteUserId,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Instagram bootstrap getMe failed; continuing with env values', [
                'error' => $e->getMessage(),
            ]);
        }

        $account = InstagramAccount::query()
            ->where('instagram_user_id', $userId)
            ->first();

        $attributes = [
            'instagram_user_id' => $userId,
            'username' => $username,
            'access_token' => $token,
            'status' => InstagramAccountStatus::Active,
            'last_error' => null,
        ];

        if ($account) {
            $account->fill($attributes);
            $account->save();
        } else {
            $account = InstagramAccount::create($attributes);
        }

        $this->resolved = $account->fresh();

        return $this->resolved;
    }

    public function resolveAccount(): InstagramAccount
    {
        if ($this->resolved) {
            return $this->resolved;
        }

        $account = InstagramAccount::query()
            ->where('status', InstagramAccountStatus::Active)
            ->orderByDesc('updated_at')
            ->first();

        if ($account && filled($account->access_token)) {
            return $this->resolved = $account;
        }

        $envToken = (string) config('instagram.access_token');

        if ($envToken === '') {
            throw new InstagramConfigurationException(
                'No active Instagram account found and INSTAGRAM_ACCESS_TOKEN is empty.'
            );
        }

        return $this->resolved = $this->bootstrapFromEnv();
    }

    public function accessToken(): string
    {
        $token = (string) $this->resolveAccount()->access_token;

        if ($token === '') {
            throw new InstagramConfigurationException('Instagram access token is empty.');
        }

        return $token;
    }

    public function refreshIfNeeded(bool $force = false): ?InstagramAccount
    {
        $account = $this->resolveAccount();

        if ($account->isTokenExpired()) {
            $account->update([
                'status' => InstagramAccountStatus::NeedsReauth,
                'last_error' => 'Access token already expired; manual re-authentication required.',
            ]);

            $this->resolved = $account->fresh();

            return $this->resolved;
        }

        $daysBefore = (int) config('instagram.token_refresh_days_before', 7);

        if (! $force && ! $account->tokenExpiresSoon($daysBefore)) {
            return $account;
        }

        try {
            $refreshed = $this->apiClient->refreshLongLivedToken((string) $account->access_token);

            $expiresIn = (int) ($refreshed['expires_in'] ?? 0);

            $account->update([
                'access_token' => $refreshed['access_token'],
                'access_token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : $account->access_token_expires_at,
                'last_refreshed_at' => now(),
                'status' => InstagramAccountStatus::Active,
                'last_error' => null,
            ]);
        } catch (InstagramApiException $e) {
            $account->update([
                'status' => InstagramAccountStatus::NeedsReauth,
                'last_error' => InstagramApiException::sanitizeMessage($e->getMessage()),
            ]);

            Log::error('Instagram token refresh failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ]);
        } catch (Throwable $e) {
            $account->update([
                'status' => InstagramAccountStatus::NeedsReauth,
                'last_error' => 'Token refresh failed: '.$e->getMessage(),
            ]);

            Log::error('Instagram token refresh failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->resolved = $account->fresh();

        return $this->resolved;
    }
}
