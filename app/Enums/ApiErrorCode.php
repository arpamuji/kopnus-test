<?php

namespace App\Enums;

use Symfony\Component\HttpFoundation\Response;

/**
 * Stable machine-readable error codes returned in the API envelope.
 *
 * Clients branch on these codes; human messages may change, codes may not.
 */
enum ApiErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';

    case InsufficientBalance = 'INSUFFICIENT_BALANCE';

    case Unauthorized = 'UNAUTHORIZED';

    case InvalidCredentials = 'INVALID_CREDENTIALS';

    case TokenMissing = 'TOKEN_MISSING';

    case TokenInvalid = 'TOKEN_INVALID';

    case TokenExpired = 'TOKEN_EXPIRED';

    case Forbidden = 'FORBIDDEN';

    case NotFound = 'NOT_FOUND';

    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';

    case Conflict = 'CONFLICT';

    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    case RateLimited = 'RATE_LIMITED';

    case InternalError = 'INTERNAL_ERROR';

    /**
     * HTTP status paired with the code.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed, self::InsufficientBalance => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::Unauthorized, self::InvalidCredentials, self::TokenMissing,
            self::TokenInvalid, self::TokenExpired => Response::HTTP_UNAUTHORIZED,
            self::Forbidden => Response::HTTP_FORBIDDEN,
            self::NotFound => Response::HTTP_NOT_FOUND,
            self::MethodNotAllowed => Response::HTTP_METHOD_NOT_ALLOWED,
            self::Conflict => Response::HTTP_CONFLICT,
            self::ServiceUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            self::RateLimited => Response::HTTP_TOO_MANY_REQUESTS,
            self::InternalError => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * Default human readable message; callers may pass a more specific one.
     */
    public function message(): string
    {
        return match ($this) {
            self::ValidationFailed => 'The given data was invalid.',
            self::InsufficientBalance => 'Insufficient balance.',
            self::Unauthorized => 'Authentication is required.',
            self::InvalidCredentials => 'Email or password is incorrect.',
            self::TokenMissing => 'A bearer token is required.',
            self::TokenInvalid => 'The bearer token is invalid.',
            self::TokenExpired => 'The bearer token has expired.',
            self::Forbidden => 'You are not allowed to perform this action.',
            self::NotFound => 'The requested resource was not found.',
            self::MethodNotAllowed => 'The HTTP method is not allowed for this endpoint.',
            self::Conflict => 'The request conflicts with the current state.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
            self::RateLimited => 'Too many requests. Try again later.',
            self::InternalError => 'An unexpected error occurred.',
        };
    }
}
