<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'operation' => $request->route()?->getName(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $traceId;
    }

    /**
     * Close the request row with its response outcome.
     */
    public function finish(string $traceId, ?User $user, int $statusCode, ?Throwable $failure = null): void
    {
        DB::table('request_logs')->where('trace_id', $traceId)->update([
            'user_id' => $user?->id,
            'status_code' => $statusCode,
            'outcome' => $statusCode < Response::HTTP_BAD_REQUEST
                ? self::OUTCOME_SUCCESS
                : self::OUTCOME_FAILURE,
            'error_type' => $failure === null ? null : class_basename($failure),
            'updated_at' => now(),
        ]);
    }
}
