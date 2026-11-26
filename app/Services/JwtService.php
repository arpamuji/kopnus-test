<?php

namespace App\Services;

use App\Exceptions\InvalidTokenException;
use InvalidArgumentException;

/**
 * Issues and verifies minimal HS256 JSON Web Tokens without an external package.
 *
 * Only `sub`, `iat`, and `exp` are used; there is no refresh, revocation, `jti`,
 * or `iss` support. Signing inputs are injected so the service is unit testable
 * without the application container.
 */
final class JwtService
{
    private const string ALGORITHM = 'HS256';

    private const int TOKEN_SEGMENT_COUNT = 3;

    /**
     * @param  string  $secret  HMAC signing key taken from configuration.
     * @param  int  $ttlSeconds  Token lifetime in seconds.
     *
     * @throws InvalidArgumentException When the secret is empty, since a blank key makes signatures trivially forgeable.
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds,
    ) {
        if ($this->secret === '') {
            throw new InvalidArgumentException('JWT secret must not be empty.');
        }
    }

    /**
     * Issue a signed token that identifies the given subject.
     *
     * @return array{token: string, expires_at: int, expires_in: int}
     */
    public function issue(string $subject): array
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->ttlSeconds;

        $segments = [
            $this->encodeSegment(['alg' => self::ALGORITHM, 'typ' => 'JWT']),
            $this->encodeSegment([
                'sub' => $subject,
                'iat' => $issuedAt,
                'exp' => $expiresAt,
            ]),
        ];

        return [
            'token' => implode('.', [...$segments, $this->sign(implode('.', $segments))]),
            'expires_at' => $expiresAt,
            'expires_in' => $this->ttlSeconds,
        ];
    }

    /**
     * Verify signature and expiry, then return the token claims.
     *
     * @return array{sub: string, iat: int, exp: int}
     *
     * @throws InvalidTokenException When the token is malformed, tampered with, incomplete, or expired.
     */
    public function parse(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== self::TOKEN_SEGMENT_COUNT) {
            throw InvalidTokenException::malformed();
        }

        [$header, $payload, $signature] = $segments;

        if (! hash_equals($this->sign($header.'.'.$payload), $signature)) {
            throw InvalidTokenException::signatureInvalid();
        }

        $claims = $this->decodeSegment($payload);

        if (! $this->hasValidClaims($claims)) {
            throw InvalidTokenException::claimsInvalid();
        }

        if ($claims['exp'] <= time()) {
            // Signature is already verified, so the subject is trustworthy.
            throw InvalidTokenException::expired($claims['sub']);
        }

        return $claims;
    }

    /**
     * Sign the given data with HMAC-SHA256 over the configured secret.
     */
    private function sign(string $data): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $this->secret, true));
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    private function encodeSegment(array $segment): string
    {
        return $this->base64UrlEncode(json_encode($segment, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws InvalidTokenException When the payload is not base64url encoded JSON.
     */
    private function decodeSegment(string $segment): array
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($decoded === false) {
            throw InvalidTokenException::malformed();
        }

        $claims = json_decode($decoded, true);

        if (! is_array($claims)) {
            throw InvalidTokenException::malformed();
        }

        return $claims;
    }

    /**
     * Check that required claims are present with the expected types.
     *
     * @param  array<array-key, mixed>  $claims
     */
    private function hasValidClaims(array $claims): bool
    {
        return is_string($claims['sub'] ?? null)
            && $claims['sub'] !== ''
            && is_int($claims['iat'] ?? null)
            && is_int($claims['exp'] ?? null);
    }

    /**
     * Encode as base64url without padding, as required by RFC 7515.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
