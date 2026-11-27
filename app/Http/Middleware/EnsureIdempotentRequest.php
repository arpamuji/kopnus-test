<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\RequestLogRepository;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes financial mutations safe to retry.
 *
 * The first request with a key claims it; a duplicate with the same payload
 * replays the stored response, a duplicate with a different payload is
 * rejected, and a claim older than the timeout may be reclaimed.
 */
final class EnsureIdempotentRequest
{
    public const string HEADER = 'Idempotency-Key';

    public const int PROCESSING_TIMEOUT_SECONDS = 60;

    public const int MAX_KEY_LENGTH = 255;

    private const int MAX_CLAIM_ATTEMPTS = 3;

    public function __construct(
        private readonly RequestLogRepository $requests,
        private readonly AuditLogRepository $audit,
    ) {}

    /**
     * Claim the key, execute once, and replay or reject duplicates.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $operation = 'deposit'): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Idempotency requires an authenticated user.');
        }

        $traceId = (string) $request->attributes->get(RecordApiRequest::TRACE_ID_ATTRIBUTE);
        $request->attributes->set(RecordApiRequest::OPERATION_ATTRIBUTE, $operation);

        $key = trim((string) $request->header(self::HEADER));
        $this->assertKeyValid($key, $traceId, $user, $operation);

        $fingerprint = $this->fingerprint($request);

        for ($attempt = 1; $attempt <= self::MAX_CLAIM_ATTEMPTS; $attempt++) {
            $existing = $this->requests->findByIdempotencyKey($user, $operation, $key);

            if ($existing !== null) {
                $replay = $this->resolveDuplicate($existing, $fingerprint, $traceId);

                if ($replay !== null) {
                    return $replay;
                }

                continue;
            }

            try {
                if (! $this->requests->claim($traceId, $user, $operation, $key, $fingerprint)) {
                    throw new RuntimeException('The request log row for this request is missing.');
                }
            } catch (QueryException $failure) {
                // The partial unique index lost the race; retry as a duplicate
                // unless the winning row has already disappeared.
                if ($this->requests->findByIdempotencyKey($user, $operation, $key) === null) {
                    throw $failure;
                }

                continue;
            }

            return $this->execute($request, $next, $operation, $traceId);
        }

        throw $this->conflict('The idempotency key is already in use. Try again.');
    }

    /**
     * Run the request and persist the outcome its claim should replay.
     *
     * @param  Closure(Request): Response  $next
     */
    private function execute(Request $request, Closure $next, string $operation, string $traceId): Response
    {
        try {
            $response = $next($request);
        } catch (ValidationException $failure) {
            $this->requests->releaseClaim($traceId);

            throw $failure;
        } catch (Throwable $failure) {
            $this->markFailed($request, $failure, $traceId);

            throw $failure;
        }

        $status = $response->getStatusCode();
        $payload = (string) $response->getContent();

        if ($status === Response::HTTP_UNPROCESSABLE_ENTITY) {
            $this->requests->releaseClaim($traceId);

            return $response;
        }

        if ($status < Response::HTTP_BAD_REQUEST) {
            $this->requests->storeReplayPayload($traceId, $status, $payload);
        } else {
            $this->requests->markFailed($traceId, $status, $payload);
        }

        return $response;
    }

    /**
     * Resolve a key that already belongs to a row.
     *
     * Returns the response to send, or null when a stale claim was retired and
     * the caller should claim the key again.
     */
    private function resolveDuplicate(stdClass $existing, string $fingerprint, string $traceId): ?Response
    {
        if ($existing->request_fingerprint !== $fingerprint) {
            throw $this->conflict('This idempotency key was already used with a different payload.');
        }

        if ($existing->idempotency_status === RequestLogRepository::IDEMPOTENCY_PROCESSING) {
            if ($this->ageInSeconds($existing) <= self::PROCESSING_TIMEOUT_SECONDS) {
                throw $this->conflict('A request with this idempotency key is still being processed.');
            }

            $this->requests->retireStaleClaim($existing->id);

            return null;
        }

        if ($existing->response_payload === null) {
            throw $this->conflict('This idempotency key has no stored response. Use a new key.');
        }

        $this->requests->markReplayed($traceId);

        return new Response(
            (string) $existing->response_payload,
            (int) $existing->status_code,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Mark a claimed request failed, storing the envelope clients would see.
     */
    private function markFailed(Request $request, Throwable $failure, string $traceId): void
    {
        try {
            $response = app(ExceptionHandler::class)->render($request, $failure);
        } catch (Throwable) {
            // Without a rendered envelope the claim stays processing and is
            // reclaimed after the timeout instead of replaying nothing.
            return;
        }

        $this->requests->markFailed($traceId, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * Reject a missing, blank, or oversized key before any claim exists.
     */
    private function assertKeyValid(string $key, string $traceId, User $user, string $operation): void
    {
        $message = match (true) {
            $key === '' => 'The idempotency key is required.',
            Str::length($key) > self::MAX_KEY_LENGTH => 'The idempotency key may not be greater than '.self::MAX_KEY_LENGTH.' characters.',
            default => null,
        };

        if ($message === null) {
            return;
        }

        $this->audit->record(
            $traceId,
            $user,
            $operation,
            AuditLogRepository::RESULT_FAILURE,
            AuditLogRepository::FAILURE_VALIDATION_FAILED,
        );

        throw ValidationException::withMessages([self::HEADER => $message]);
    }

    /**
     * Build a stable fingerprint of the method, path, and canonical body.
     *
     * Body values keep their JSON type, so `1000` and `1000.5` never share a
     * fingerprint; the raw body is used when JSON cannot be decoded.
     */
    private function fingerprint(Request $request): string
    {
        try {
            $body = $this->canonicalize($request->json()->all());
        } catch (Throwable) {
            $body = $request->getContent();
        }

        return hash('sha256', json_encode([
            'method' => $request->method(),
            'path' => $request->path(),
            'body' => $body,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $body): array
    {
        foreach ($body as $field => $value) {
            if (is_array($value)) {
                $body[$field] = $this->canonicalize($value);
            }
        }

        ksort($body);

        return $body;
    }

    private function ageInSeconds(stdClass $existing): int
    {
        return now()->getTimestamp() - Carbon::parse($existing->updated_at)->getTimestamp();
    }

    private function conflict(string $message): ApiException
    {
        return new ApiException(
            ApiErrorCode::Conflict,
            [[
                'code' => ApiErrorCode::Conflict->value,
                'field' => self::HEADER,
                'message' => $message,
            ]],
            $message,
        );
    }
}
