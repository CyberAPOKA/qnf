<?php

namespace Tests\Unit\Instagram;

use App\Instagram\Enums\InstagramPublicationType;
use App\Instagram\Enums\InstagramTriggerType;
use App\Instagram\Exceptions\InstagramApiException;
use App\Instagram\Support\InstagramIdempotencyKey;
use App\Instagram\Support\InstagramUsernameNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstagramUsernameNormalizerTest extends TestCase
{
    #[DataProvider('validUsernames')]
    public function test_normalizes_valid_usernames(string $input, string $expected): void
    {
        $this->assertSame($expected, InstagramUsernameNormalizer::normalize($input));
    }

    public static function validUsernames(): array
    {
        return [
            'plain' => ['christian.steffens_', 'christian.steffens_'],
            'at' => ['@christian.steffens_', 'christian.steffens_'],
            'url' => ['https://instagram.com/christian.steffens_', 'christian.steffens_'],
            'url www' => ['https://www.instagram.com/QnfPorto/', 'qnfporto'],
            'spaces' => ['  @Foo.Bar  ', 'foo.bar'],
            'uppercase' => ['MyUser.Name', 'myuser.name'],
        ];
    }

    public function test_rejects_invalid_usernames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InstagramUsernameNormalizer::normalize('bad username!');
    }

    public function test_rejects_leading_dot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InstagramUsernameNormalizer::normalize('.starts.with.dot');
    }

    public function test_null_and_empty(): void
    {
        $this->assertNull(InstagramUsernameNormalizer::normalize(null));
        $this->assertNull(InstagramUsernameNormalizer::normalize(''));
        $this->assertNull(InstagramUsernameNormalizer::normalize('   '));
    }

    public function test_profile_url(): void
    {
        $this->assertSame(
            'https://instagram.com/qnfporto',
            InstagramUsernameNormalizer::profileUrl('@QNFPorto')
        );
    }

    public function test_idempotency_key(): void
    {
        $key = InstagramIdempotencyKey::make(
            InstagramTriggerType::MatchResult,
            456,
            'v3',
            InstagramPublicationType::WeeklyTeamStory,
            'team-12'
        );

        $this->assertSame('match-result:456:v3:weekly-team-story:team-12', $key);
    }

    public function test_api_exception_classifies_permanent_and_sanitizes_token(): void
    {
        $exception = InstagramApiException::fromResponse([
            'error' => [
                'message' => 'Invalid OAuth access token. access_token=SECRET123&foo=1',
                'code' => 190,
                'error_subcode' => 463,
                'type' => 'OAuthException',
            ],
        ], 400);

        $this->assertTrue($exception->permanent);
        $this->assertFalse($exception->transient);
        $this->assertStringNotContainsString('SECRET123', $exception->getMessage());
        $this->assertStringContainsString('[redacted]', $exception->getMessage());
    }

    public function test_api_exception_classifies_transient(): void
    {
        $exception = InstagramApiException::fromResponse([
            'error' => [
                'message' => 'Please try again later',
                'code' => 2,
                'is_transient' => true,
            ],
        ], 500);

        $this->assertTrue($exception->transient);
        $this->assertFalse($exception->permanent);
    }
}
