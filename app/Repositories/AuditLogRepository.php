<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists audit records for business actions.
 *
 * Entries reference the request trace id and never carry tokens or request
 * bodies, only actor, action, subject, result, and failure reason.
 */
final class AuditLogRepository
{
    public const string ACTION_LOGIN = 'login';

    public const string ACTION_AUTHENTICATE = 'authenticate';

    public const string RESULT_SUCCESS = 'success';

    public const string RESULT_FAILURE = 'failure';

    public const string FAILURE_INVALID_CREDENTIALS = 'invalid_credentials';

    public const string FAILURE_TOKEN_EXPIRED = 'token_expired';

    /**
     * Write one audit record.
     *
     * @param  string  $traceId  Trace id of the originating request.
     * @param  User|null  $user  Actor; null when the request could not identify one.
     * @param  string  $action  Business action performed.
     * @param  string  $result  Outcome of the action.
     * @param  string|null  $failureReason  Stable reason when the result is a failure.
     */
    public function record(
        string $traceId,
        ?User $user,
        string $action,
        string $result,
        ?string $failureReason = null,
    ): void {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'trace_id' => $traceId,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $user === null ? null : User::class,
            'subject_id' => $user?->id,
            'result' => $result,
            'failure_reason' => $failureReason,
            'created_at' => now(),
        ]);
    }
}
