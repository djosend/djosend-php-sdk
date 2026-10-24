<?php

declare(strict_types=1);

namespace Djosend\Exception;

final class ApiException extends DjosendException
{
    /** @param array<string, mixed>|null $responseBody */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $requestId = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}
