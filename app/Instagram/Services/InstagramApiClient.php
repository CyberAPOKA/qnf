<?php

namespace App\Instagram\Services;

use App\Instagram\Data\InstagramContainerData;
use App\Instagram\Exceptions\InstagramApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class InstagramApiClient
{
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->send('post', $path, $data);
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function refreshLongLivedToken(string $token): array
    {
        $body = $this->get('refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ]);

        if (empty($body['access_token'])) {
            throw new InstagramApiException('Token refresh response missing access_token.');
        }

        return [
            'access_token' => (string) $body['access_token'],
            'expires_in' => (int) ($body['expires_in'] ?? 0),
        ];
    }

    public function getMe(string $token): array
    {
        return $this->get('me', [
            'fields' => 'user_id,username,account_type',
            'access_token' => $token,
        ]);
    }

    public function createMediaContainer(string $igUserId, string $token, array $params): InstagramContainerData
    {
        $body = $this->post(ltrim($igUserId, '/').'/media', array_merge($params, [
            'access_token' => $token,
        ]));

        $id = (string) ($body['id'] ?? '');

        if ($id === '') {
            throw new InstagramApiException('Media container response missing id.');
        }

        return new InstagramContainerData(
            id: $id,
            statusCode: isset($body['status_code']) ? (string) $body['status_code'] : null,
            status: isset($body['status']) ? (string) $body['status'] : null,
        );
    }

    public function getContainerStatus(string $containerId, string $token): InstagramContainerData
    {
        $body = $this->get(ltrim($containerId, '/'), [
            'fields' => 'id,status_code,status',
            'access_token' => $token,
        ]);

        $id = (string) ($body['id'] ?? $containerId);

        return new InstagramContainerData(
            id: $id,
            statusCode: isset($body['status_code']) ? (string) $body['status_code'] : null,
            status: isset($body['status']) ? (string) $body['status'] : null,
        );
    }

    /**
     * @return array{id: string}
     */
    public function publishMedia(string $igUserId, string $token, string $creationId): array
    {
        $body = $this->post(ltrim($igUserId, '/').'/media_publish', [
            'creation_id' => $creationId,
            'access_token' => $token,
        ]);

        $id = (string) ($body['id'] ?? '');

        if ($id === '') {
            throw new InstagramApiException('Publish response missing id.');
        }

        return ['id' => $id];
    }

    public function getMediaPermalink(string $mediaId, string $token): ?string
    {
        $body = $this->get(ltrim($mediaId, '/'), [
            'fields' => 'permalink',
            'access_token' => $token,
        ]);

        $permalink = $body['permalink'] ?? null;

        return is_string($permalink) && $permalink !== '' ? $permalink : null;
    }

    public function getPublishingLimit(string $igUserId, string $token): array
    {
        return $this->get(ltrim($igUserId, '/').'/content_publishing_limit', [
            'fields' => 'config,quota_usage',
            'access_token' => $token,
        ]);
    }

    private function send(string $method, string $path, array $payload = []): array
    {
        $url = $this->url($path);

        try {
            /** @var Response $response */
            $response = $method === 'post'
                ? $this->pendingRequest()->asForm()->post($url, $payload)
                : $this->pendingRequest()->get($url, $payload);
        } catch (ConnectionException $e) {
            throw new InstagramApiException(
                message: 'Instagram API connection failed.',
                transient: true,
                previous: $e,
            );
        } catch (RequestException $e) {
            $response = $e->response;
            $body = is_array($response?->json()) ? $response->json() : ['error' => ['message' => $e->getMessage()]];

            throw InstagramApiException::fromResponse($body, $response?->status() ?? 0);
        }

        if ($response->failed()) {
            $body = is_array($response->json()) ? $response->json() : ['error' => ['message' => $response->body()]];

            throw InstagramApiException::fromResponse($body, $response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function pendingRequest(): PendingRequest
    {
        $tries = max(1, (int) config('instagram.http.retries', 2) + 1);

        return Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => (string) config('instagram.http.user_agent', 'QNF-Instagram/1.0'),
        ])
            ->connectTimeout((int) config('instagram.http.connect_timeout', 10))
            ->timeout((int) config('instagram.http.timeout', 60))
            ->retry(
                $tries,
                1000,
                function (\Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        $status = $exception->response?->status() ?? 0;

                        return $status === 429 || $status >= 500;
                    }

                    return false;
                },
                false,
            );
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('instagram.graph_base_url'), '/');
        $version = trim((string) config('instagram.graph_version'), '/');
        $path = ltrim($path, '/');

        return "{$base}/{$version}/{$path}";
    }
}
