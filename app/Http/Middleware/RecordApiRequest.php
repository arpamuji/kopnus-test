<?php

namespace App\Http\Middleware;

use App\Repositories\RequestLogRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Persists a request log row for every API request, successful or failed.
 *
 * Runs as global middleware so requests that never match a route, such as
 * unknown paths and disallowed methods, are recorded too; exposes the trace id
 * for audit correlation.
 */
final class RecordApiRequest
{
    public const string TRACE_ID_ATTRIBUTE = 'trace_id';

    public const string OPERATION_ATTRIBUTE = 'operation';

    public function __construct(private readonly RequestLogRepository $requests) {}

    /**
     * Wrap the request in a trace and persist its outcome.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $traceId = $this->requests->start($request);
        $request->attributes->set(self::TRACE_ID_ATTRIBUTE, $traceId);

        try {
            $response = $next($request);
        } catch (Throwable $failure) {
            // Routing failures such as 404 and 405 are rendered by the kernel
            // outside the route pipeline, so they surface here as exceptions.
            $this->requests->finish(
                $traceId,
                $request->user(),
                $failure instanceof HttpExceptionInterface
                    ? $failure->getStatusCode()
                    : Response::HTTP_INTERNAL_SERVER_ERROR,
                $this->operation($request),
                $failure,
            );

            throw $failure;
        }

        // The routing pipeline converts failures into rendered responses before
        // they reach this point and attaches the original exception to them.
        $this->requests->finish(
            $traceId,
            $request->user(),
            $response->getStatusCode(),
            $this->operation($request),
            $response->exception ?? null,
        );

        return $response;
    }

    /**
     * Resolve the operation name, letting route middleware override the route name.
     */
    private function operation(Request $request): ?string
    {
        return $request->attributes->get(self::OPERATION_ATTRIBUTE) ?? $request->route()?->getName();
    }
}
