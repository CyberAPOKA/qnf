<?php

namespace App\Instagram\Exceptions;

use Exception;
use Throwable;

class InstagramApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly ?int $errorSubcode = null,
        public readonly ?string $errorType = null,
        public readonly bool $transient = false,
        public readonly bool $permanent = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode ?? 0, $previous);
    }

    public static function fromResponse(array $body, int $httpStatus = 0): self
    {
        $error = $body['error'] ?? $body;
        $message = (string) ($error['message'] ?? 'Instagram API error');
        $code = isset($error['code']) ? (int) $error['code'] : $httpStatus;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $type = isset($error['type']) ? (string) $error['type'] : null;
        $isTransient = (bool) ($error['is_transient'] ?? false);

        $permanent = self::classifyPermanent($code, $subcode, $message);
        $transient = $isTransient || self::classifyTransient($code, $httpStatus, $message);

        return new self(
            message: self::sanitizeMessage($message),
            errorCode: $code,
            errorSubcode: $subcode,
            errorType: $type,
            transient: $transient && ! $permanent,
            permanent: $permanent,
        );
    }

    public static function sanitizeMessage(string $message): string
    {
        return preg_replace('/access_token=[^&\s]+/i', 'access_token=[redacted]', $message) ?? $message;
    }

    private static function classifyPermanent(int $code, ?int $subcode, string $message): bool
    {
        if (in_array($code, [10, 100, 190, 200, 220, 368], true)) {
            return true;
        }

        if (in_array($subcode, [463, 467, 458, 460, 492], true)) {
            return true;
        }

        $lower = strtolower($message);

        return str_contains($lower, 'permission')
            || str_contains($lower, 'invalid oauth')
            || str_contains($lower, 'session has expired')
            || str_contains($lower, 'unsupported format');
    }

    private static function classifyTransient(int $code, int $httpStatus, string $message): bool
    {
        if (in_array($httpStatus, [408, 429, 500, 502, 503, 504], true)) {
            return true;
        }

        if (in_array($code, [1, 2, 4, 17], true)) {
            return true;
        }

        $lower = strtolower($message);

        return str_contains($lower, 'temporarily')
            || str_contains($lower, 'try again')
            || str_contains($lower, 'rate limit');
    }
}
