<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use RuntimeException;

/**
 * Expected business rejection that renders as an API error envelope.
 *
 * Services throw this instead of HTTP exceptions so they stay transport
 * agnostic while responses keep one stable envelope.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  ApiErrorCode  $errorCode  Stable error code; also decides the HTTP status.
     * @param  list<array{code: string, field: string|null, message: string}>  $errors  Field level detail when the code alone is not enough.
     */
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        public readonly array $errors = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode->message());
    }
}
