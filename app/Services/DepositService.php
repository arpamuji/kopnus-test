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
 * Credits member accounts and records the matching ledger entry.
 */
final class DepositService
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
     * Credit an amount to the caller's account.
     *
     * The ledger row, the success audit, and the idempotency completion commit
     * or roll back together, so a lost claim cannot leave money moved without
     * the request being marked done.
     *
     * @param  string  $traceId  Trace id of the originating request.
     * @return array{balance: int, transaction: array{transaction_id: string, type: string, amount: int, balance_before: int, balance_after: int, created_at: string}}
     *
     * @throws ApiException With `VALIDATION_FAILED` for an amount outside the allowed range, or `NOT_FOUND` when the user has no account.
     */
    public function deposit(User $user, int $amount, string $traceId): array
    {
        $this->assertAmountWithinBounds($amount);

        try {
            return DB::transaction(function () use ($user, $amount, $traceId): array {
                $account = $this->accounts->findByUserIdForUpdate($user->getKey());

                if ($account === null) {
                    throw new ApiException(ApiErrorCode::NotFound, message: 'The authenticated user has no account.');
                }

                $balanceAfter = $this->accounts->credit($account, $amount);
                $balanceBefore = $balanceAfter - $amount;

                $transaction = $this->transactions->createDeposit(
                    $account,
                    $amount,
                    $balanceBefore,
                    $balanceAfter,
                    $traceId,
                );

                $this->audit->record(
                    $traceId,
                    $user,
                    AuditLogRepository::ACTION_DEPOSIT,
                    AuditLogRepository::RESULT_SUCCESS,
                    account: $account,
                );

                if (! $this->requests->markClaimCompleted($traceId)) {
                    throw new RuntimeException('The idempotency claim was lost before the deposit committed.');
                }

                return ['balance' => $balanceAfter, 'transaction' => $transaction];
            });
        } catch (ApiException $failure) {
            // The failure audit is written after rollback so a failed deposit
            // never leaves a success record behind.
            if ($failure->errorCode === ApiErrorCode::NotFound) {
                $this->audit->record(
                    $traceId,
                    $user,
                    AuditLogRepository::ACTION_DEPOSIT,
                    AuditLogRepository::RESULT_FAILURE,
                    AuditLogRepository::FAILURE_ACCOUNT_NOT_FOUND,
                );
            }

            throw $failure;
        }
    }

    /**
     * Audit a deposit payload rejected by validation for an authenticated user.
     */
    public function recordValidationFailure(User $user, string $traceId): void
    {
        $this->audit->record(
            $traceId,
            $user,
            AuditLogRepository::ACTION_DEPOSIT,
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
