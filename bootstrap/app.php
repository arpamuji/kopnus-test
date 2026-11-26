<?php

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidTokenException;
use App\Http\Middleware\AuthenticateWithToken;
use App\Http\Middleware\RecordApiRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.jwt' => AuthenticateWithToken::class,
        ]);

        // First in the group so throttling and auth failures are logged too.
        $middleware->api(prepend: [RecordApiRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Only api/* requests are rewritten; web routes keep Laravel defaults.
        $exceptions->render(fn (ApiException $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error($exception->errorCode, $exception->errors, $exception->getMessage())
            : null);

        $exceptions->render(fn (InvalidTokenException $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error(
                $exception->reason === InvalidTokenException::REASON_EXPIRED
                    ? ApiErrorCode::TokenExpired
                    : ApiErrorCode::TokenInvalid,
                message: $exception->getMessage(),
            )
            : null);

        $exceptions->render(fn (ValidationException $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error(ApiErrorCode::ValidationFailed, ApiResponse::validationErrors($exception))
            : null);

        $exceptions->render(fn (AuthenticationException $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error(ApiErrorCode::Unauthorized)
            : null);

        $exceptions->render(fn (ThrottleRequestsException $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error(ApiErrorCode::RateLimited, headers: $exception->getHeaders())
            : null);

        $exceptions->render(fn (NotFoundHttpException $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error(ApiErrorCode::NotFound)
            : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error(ApiErrorCode::MethodNotAllowed)
            : null);

        // Any other HTTP exception keeps its status instead of degrading to 500.
        $exceptions->render(fn (HttpExceptionInterface $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error(
                match ($exception->getStatusCode()) {
                    Response::HTTP_UNAUTHORIZED => ApiErrorCode::Unauthorized,
                    Response::HTTP_FORBIDDEN => ApiErrorCode::Forbidden,
                    Response::HTTP_NOT_FOUND => ApiErrorCode::NotFound,
                    Response::HTTP_METHOD_NOT_ALLOWED => ApiErrorCode::MethodNotAllowed,
                    Response::HTTP_CONFLICT => ApiErrorCode::Conflict,
                    Response::HTTP_TOO_MANY_REQUESTS => ApiErrorCode::RateLimited,
                    Response::HTTP_SERVICE_UNAVAILABLE => ApiErrorCode::ServiceUnavailable,
                    default => ApiErrorCode::InternalError,
                },
                headers: $exception->getHeaders(),
                status: $exception->getStatusCode(),
            )
            : null);

        // Last resort: never leak exception details to API clients.
        $exceptions->render(fn (Throwable $exception, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiResponse::error(ApiErrorCode::InternalError)
            : null);
    })->create();
