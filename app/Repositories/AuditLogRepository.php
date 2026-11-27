<?php

namespace App\Repositories;

use App\Models\Account;
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

    public const string ACTION_DEPOSIT = 'deposit';

    public const string RESULT_SUCCESS = 'success';

    public const string RESULT_FAILURE = 'failure';

    public const string FAILURE_INVALID_CREDENTIALS = 'invalid_credentials';

    public const string FAILURE_TOKEN_EXPIRED = 'token_expired';

    public const string FAILURE_VALIDATION_FAILED = 'validation_failed';

    public const string FAILURE_ACCOUNT_NOT_FOUND = 'account_not_found';

    /**
     * Write one audit record.
     *
     * @param  string  $traceId  Trace id of the originating request.
     * @param  User|null  $user  Actor; null when the request could not identify one.
     * @param  string  $action  Business action performed.
     * @param  string  $result  Outcome of the action.
     * @param  string|null  $failureReason  Stable reason when the result is a failure.
     * @param  Account|null  $account  Subject of the action; defaults to the acting user.
     */
    public function record(
        string $traceId,
        ?User $user,
        string $action,
        string $result,
        ?string $failureReason = null,
        ?Account $account = null,
    ): void {
        $subject = $account ?? $user;

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'trace_id' => $traceId,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => $subject?->getKey(),
            'result' => $result,
            'failure_reason' => $failureReason,
            'created_at' => now(),
        ]);
    }
}
