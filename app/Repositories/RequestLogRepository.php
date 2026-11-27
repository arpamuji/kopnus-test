<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Persists one row per API request so every attempt stays observable.
 *
 * Rows are written outside financial transactions: inserted when the request
 * starts, finalized once the outcome is known, so a crash leaves evidence.
 */
final class RequestLogRepository
{
    private const string OUTCOME_SUCCESS = 'success';

    private const string OUTCOME_FAILURE = 'failure';

    public const string IDEMPOTENCY_PROCESSING = 'processing';

    public const string IDEMPOTENCY_COMPLETED = 'completed';

    public const string IDEMPOTENCY_FAILED = 'failed';

    public const string IDEMPOTENCY_REPLAYED = 'replayed';

    public const string ERROR_IDEMPOTENCY_TIMEOUT = 'IdempotencyTimeout';

    /**
     * Insert the open request row and return its trace id.
     */
    public function start(Request $request): string
    {
        $traceId = (string) Str::uuid7();
        $now = now();

        DB::table('request_logs')->insert([
            'id' => $traceId,
            'trace_id' => $traceId,
            'method' => $request->method(),
            'route' => $request->path(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $traceId;
    }

    /**
     * Close the request row with its response outcome.
     */
    public function finish(
        string $traceId,
        ?User $user,
        int $statusCode,
        ?string $operation,
        ?Throwable $failure = null,
    ): void {
        DB::table('request_logs')->where('trace_id', $traceId)->update([
            'user_id' => $user?->id,
            'operation' => $operation,
            'status_code' => $statusCode,
            'outcome' => $statusCode < Response::HTTP_BAD_REQUEST
                ? self::OUTCOME_SUCCESS
                : self::OUTCOME_FAILURE,
            'error_type' => $failure === null ? null : class_basename($failure),
            'updated_at' => now(),
        ]);
    }

    /**
     * Find the row that already owns an idempotency key for a user and operation.
     */
    public function findByIdempotencyKey(User $user, string $operation, string $key): ?stdClass
    {
        return DB::table('request_logs')
            ->where('user_id', $user->getKey())
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Claim the current request row for an idempotency key.
     *
     * The partial unique index arbitrates concurrent claims; a losing caller
     * gets a constraint violation rather than a second claim.
     */
    public function claim(
        string $traceId,
        User $user,
        string $operation,
        string $key,
        string $fingerprint,
    ): bool {
        return DB::table('request_logs')->where('id', $traceId)->update([
            'user_id' => $user->getKey(),
            'operation' => $operation,
            'idempotency_key' => $key,
            'idempotency_status' => self::IDEMPOTENCY_PROCESSING,
            'request_fingerprint' => $fingerprint,
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * Release a claim so a corrected retry may reuse the key.
     */
    public function releaseClaim(string $traceId): void
    {
        DB::table('request_logs')->where('id', $traceId)->update([
            'idempotency_key' => null,
            'idempotency_status' => null,
            'request_fingerprint' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Retire a stale processing claim and free its key.
     *
     * Conditional on the row still processing with a key, so only one caller
     * wins the reclaim.
     */
    public function retireStaleClaim(string $traceId): bool
    {
        return DB::table('request_logs')
            ->where('id', $traceId)
            ->where('idempotency_status', self::IDEMPOTENCY_PROCESSING)
            ->whereNotNull('idempotency_key')
            ->update([
                'idempotency_key' => null,
                'idempotency_status' => self::IDEMPOTENCY_FAILED,
                'request_fingerprint' => null,
                'error_type' => self::ERROR_IDEMPOTENCY_TIMEOUT,
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * Mark the caller's claim completed inside the financial transaction.
     *
     * Returns false when the claim was retired or taken over, letting the
     * caller roll back before a second execution can occur.
     */
    public function markClaimCompleted(string $traceId): bool
    {
        return DB::table('request_logs')
            ->where('id', $traceId)
            ->where('idempotency_status', self::IDEMPOTENCY_PROCESSING)
            ->whereNotNull('idempotency_key')
            ->update([
                'idempotency_status' => self::IDEMPOTENCY_COMPLETED,
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * Attach the response body replayed to later requests with the same key.
     */
    public function storeReplayPayload(string $traceId, int $statusCode, string $payload): void
    {
        DB::table('request_logs')->where('id', $traceId)->update([
            'status_code' => $statusCode,
            'response_payload' => $payload,
            'updated_at' => now(),
        ]);
    }

    /**
     * Finalize a claimed request that failed after the claim was taken.
     */
    public function markFailed(string $traceId, int $statusCode, string $payload): void
    {
        DB::table('request_logs')
            ->where('id', $traceId)
            ->where('idempotency_status', self::IDEMPOTENCY_PROCESSING)
            ->update([
                'idempotency_status' => self::IDEMPOTENCY_FAILED,
                'status_code' => $statusCode,
                'response_payload' => $payload,
                'updated_at' => now(),
            ]);
    }

    /**
     * Flag the row of a request that replayed an earlier response.
     */
    public function markReplayed(string $traceId): void
    {
        DB::table('request_logs')->where('id', $traceId)->update([
            'idempotency_status' => self::IDEMPOTENCY_REPLAYED,
            'updated_at' => now(),
        ]);
    }
}
