<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a bearer token cannot be trusted.
 *
 * The reason separates tampering from expiry so HTTP error mapping can return
 * a stable code for each case without parsing messages.
 */
final class InvalidTokenException extends RuntimeException
{
    public const string REASON_MALFORMED = 'malformed';

    public const string REASON_SIGNATURE_INVALID = 'signature_invalid';

    public const string REASON_CLAIMS_INVALID = 'claims_invalid';

    public const string REASON_EXPIRED = 'expired';

    /**
     * @param  self::REASON_*  $reason
     * @param  string|null  $subject  Token subject; only carried when the signature was already verified.
     */
    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $subject = null,
    ) {
        parent::__construct($message);
    }

    public static function malformed(): self
    {
        return new self(self::REASON_MALFORMED, 'Token structure is invalid.');
    }

    public static function signatureInvalid(): self
    {
        return new self(self::REASON_SIGNATURE_INVALID, 'Token signature is invalid.');
    }

    public static function claimsInvalid(): self
    {
        return new self(self::REASON_CLAIMS_INVALID, 'Token claims are incomplete.');
    }

    public static function expired(?string $subject = null): self
    {
        return new self(self::REASON_EXPIRED, 'Token has expired.', $subject);
    }
}
