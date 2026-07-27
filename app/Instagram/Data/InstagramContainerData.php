<?php

namespace App\Instagram\Data;

readonly class InstagramContainerData
{
    public function __construct(
        public string $id,
        public ?string $statusCode = null,
        public ?string $status = null,
    ) {}

    public function isFinished(): bool
    {
        return strtoupper((string) $this->statusCode) === 'FINISHED';
    }

    public function isError(): bool
    {
        $code = strtoupper((string) $this->statusCode);

        return in_array($code, ['ERROR', 'EXPIRED'], true);
    }

    public function isInProgress(): bool
    {
        $code = strtoupper((string) $this->statusCode);

        return in_array($code, ['IN_PROGRESS', 'PUBLISHED'], true) || (! $this->isFinished() && ! $this->isError());
    }
}
