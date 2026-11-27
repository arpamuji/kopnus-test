<?php

namespace App\Services;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\RequestLogRepository;
use App\Repositories\TransactionRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Debits member accounts and records the matching ledger entry.
 */
final class WithdrawService
{
    public const int MIN_AMOUNT = 1;

    public const int MAX_AMOUNT = 25_000_000;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly TransactionRepository $transactions,
        private readonly AuditLogRepository $audit,
        private readonly RequestLogRepository $requests,
    ) {}

    /**
     * Debit an amount from the caller's account.
     *
     * The conditional debit, the ledger row, the success audit, and the
     * idempotency completion commit or roll back together.
     *
     * @param  User  $user  Authenticated member whose account is debited.
     * @param  int  $amount  Whole-rupiah amount to debit.
     * @param  string  $traceId  Trace id of the originating request.
     * @return array{balance: int, transaction: array{transaction_id: string, type: string, amount: int, balance_before: int, balance_after: int, created_at: string}}
     *
     * @throws ApiException With `VALIDATION_FAILED` for an amount outside the allowed range, `NOT_FOUND` when the user has no account, or `INSUFFICIENT_BALANCE` when the balance cannot cover the withdrawal.
     * @throws RuntimeException When the idempotency claim can no longer be completed.
     */
    public function withdraw(User $user, int $amount, string $traceId): array
    {
        $account = null;

        try {
            $this->assertAmountWithinBounds($amount);

            return DB::transaction(function () use ($user, $amount, $traceId, &$account): array {
                $account = $this->accounts->findByUserIdForUpdate($user->getKey());

                if ($account === null) {
                    throw new ApiException(ApiErrorCode::NotFound, message: 'The authenticated user has no account.');
                }

                $balanceAfter = $this->accounts->debit($account, $amount);

                if ($balanceAfter === null) {
                    throw new ApiException(
                        ApiErrorCode::InsufficientBalance,
                        [[
                            'code' => ApiErrorCode::InsufficientBalance->value,
                            'field' => 'amount',
                            'message' => ApiErrorCode::InsufficientBalance->message(),
                        ]],
                    );
                }

                $balanceBefore = $balanceAfter + $amount;

                $transaction = $this->transactions->createWithdraw(
                    $account,
                    $amount,
                    $balanceBefore,
                    $balanceAfter,
                    $traceId,
                );

                $this->audit->record(
                    $traceId,
                    $user,
                    AuditLogRepository::ACTION_WITHDRAW,
                    AuditLogRepository::RESULT_SUCCESS,
                    account: $account,
                );

                if (! $this->requests->markClaimCompleted($traceId)) {
                    throw new RuntimeException('The idempotency claim was lost before the withdrawal committed.');
                }

                return ['balance' => $balanceAfter, 'transaction' => $transaction];
            });
        } catch (ApiException $failure) {
            // The failure audit is written after rollback so a failed withdrawal
            // never leaves a success record behind.
            $reason = match ($failure->errorCode) {
                ApiErrorCode::NotFound => AuditLogRepository::FAILURE_ACCOUNT_NOT_FOUND,
                ApiErrorCode::InsufficientBalance => AuditLogRepository::FAILURE_INSUFFICIENT_BALANCE,
                ApiErrorCode::ValidationFailed => AuditLogRepository::FAILURE_VALIDATION_FAILED,
            };

            $this->audit->record(
                $traceId,
                $user,
                AuditLogRepository::ACTION_WITHDRAW,
                AuditLogRepository::RESULT_FAILURE,
                $reason,
                $account,
            );

            throw $failure;
        }
    }

    /**
     * Audit a withdrawal payload rejected by validation for an authenticated user.
     *
     * @param  User  $user  Authenticated member who sent the payload.
     * @param  string  $traceId  Trace id of the originating request.
     */
    public function recordValidationFailure(User $user, string $traceId): void
    {
        $this->audit->record(
            $traceId,
            $user,
            AuditLogRepository::ACTION_WITHDRAW,
            AuditLogRepository::RESULT_FAILURE,
            AuditLogRepository::FAILURE_VALIDATION_FAILED,
        );
    }

    /**
     * Reject amounts the API must never move, even if validation is bypassed.
     */
    private function assertAmountWithinBounds(int $amount): void
    {
        if ($amount >= self::MIN_AMOUNT && $amount <= self::MAX_AMOUNT) {
            return;
        }

        throw new ApiException(
            ApiErrorCode::ValidationFailed,
            [[
                'code' => ApiErrorCode::ValidationFailed->value,
                'field' => 'amount',
                'message' => 'The amount must be between '.self::MIN_AMOUNT.' and '.self::MAX_AMOUNT.'.',
            ]],
        );
    }
}
