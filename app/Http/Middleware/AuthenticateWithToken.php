<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidTokenException;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates API requests using a signed bearer token.
 *
 * Authentication lives here so controllers and services never parse tokens,
 * and the resolved user is bound to the request for downstream consumers.
 */
final class AuthenticateWithToken
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws ApiException When the token is absent or refers to a user that no longer exists.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new ApiException(ApiErrorCode::TokenMissing);
        }

        try {
            $subject = $this->jwt->parse($token)['sub'];
        } catch (InvalidTokenException $exception) {
            $this->auditExpiredToken($request, $exception);

            throw $exception;
        }

        $user = $this->users->findById($subject);

        if ($user === null) {
            throw new ApiException(ApiErrorCode::TokenInvalid);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * Audit an expired token against its user, which is trustworthy because
     * the signature was verified before the expiry claim was rejected.
     */
    private function auditExpiredToken(Request $request, InvalidTokenException $exception): void
    {
        $traceId = $request->attributes->get(RecordApiRequest::TRACE_ID_ATTRIBUTE);

        if ($exception->reason !== InvalidTokenException::REASON_EXPIRED
            || $exception->subject === null
            || ! is_string($traceId)) {
            return;
        }

        $user = $this->users->findById($exception->subject);

        if ($user === null) {
            return;
        }

        $this->audit->record(
            $traceId,
            $user,
            AuditLogRepository::ACTION_AUTHENTICATE,
            AuditLogRepository::RESULT_FAILURE,
            AuditLogRepository::FAILURE_TOKEN_EXPIRED,
        );
    }
}
