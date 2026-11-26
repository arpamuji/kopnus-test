<?php

namespace App\Services;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Authenticates users and issues bearer tokens.
 *
 * Dependencies are injected so the service can be tested without the container.
 */
final class AuthService
{
    /**
     * Bcrypt hash of an arbitrary string, used to spend the same time on a
     * password check when the email is unknown. Without it, response timing
     * reveals which emails are registered.
     */
    private const string DUMMY_PASSWORD_HASH = '$2y$12$nOGXmX6mV24QiM5tR4WOB.aYzesV99yFljB20Fl39TsrV4jMw9972';

    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtService $jwt,
        private readonly Hasher $hasher,
        private readonly AuditLogRepository $audit,
    ) {}

    /**
     * Verify credentials and issue a bearer token.
     *
     * @param  string  $traceId  Trace id of the originating request, stored on the audit record.
     * @return array{access_token: string, token_type: string, expires_in: int}
     *
     * @throws ApiException With `INVALID_CREDENTIALS` when the email is unknown or the password does not match.
     */
    public function login(string $email, string $password, string $traceId): array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            $this->hasher->check($password, self::DUMMY_PASSWORD_HASH);
            $this->recordFailure($traceId, null);

            throw new ApiException(ApiErrorCode::InvalidCredentials);
        }

        if (! $this->hasher->check($password, $user->password)) {
            $this->recordFailure($traceId, $user);

            throw new ApiException(ApiErrorCode::InvalidCredentials);
        }

        $this->audit->record(
            $traceId,
            $user,
            AuditLogRepository::ACTION_LOGIN,
            AuditLogRepository::RESULT_SUCCESS,
        );

        return $this->issueToken($user);
    }

    /**
     * Record a rejected login attempt, identifying the user when the email exists.
     */
    private function recordFailure(string $traceId, ?User $user): void
    {
        $this->audit->record(
            $traceId,
            $user,
            AuditLogRepository::ACTION_LOGIN,
            AuditLogRepository::RESULT_FAILURE,
            AuditLogRepository::FAILURE_INVALID_CREDENTIALS,
        );
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    private function issueToken(User $user): array
    {
        $issued = $this->jwt->issue($user->id);

        return [
            'access_token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
        ];
    }
}
