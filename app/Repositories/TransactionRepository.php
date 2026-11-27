<?php

namespace App\Repositories;

use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persistence access for the financial ledger.
 */
final class TransactionRepository
{
    public const string TYPE_DEPOSIT = 'deposit';

    public const string TYPE_WITHDRAW = 'withdraw';

    public const string PUBLIC_ID_DATE_FORMAT = 'dmy';

    public const int PUBLIC_ID_GROUP_LENGTH = 4;

    public const int PUBLIC_ID_SUFFIX_LENGTH = 6;

    /**
     * Record a deposit ledger row.
     *
     * @return array{transaction_id: string, type: string, amount: int, balance_before: int, balance_after: int, created_at: string}
     */
    public function createDeposit(
        Account $account,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
        string $traceId,
    ): array {
        return $this->record($account, self::TYPE_DEPOSIT, $amount, $balanceBefore, $balanceAfter, $traceId);
    }

    /**
     * Record a withdrawal ledger row.
     *
     * @param  Account  $account  Account the withdrawal debits.
     * @param  int  $amount  Whole-rupiah amount withdrawn.
     * @param  int  $balanceBefore  Balance before the withdrawal.
     * @param  int  $balanceAfter  Balance after the withdrawal.
     * @param  string  $traceId  Trace id of the originating request.
     * @return array{transaction_id: string, type: string, amount: int, balance_before: int, balance_after: int, created_at: string}
     */
    public function createWithdraw(
        Account $account,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
        string $traceId,
    ): array {
        return $this->record($account, self::TYPE_WITHDRAW, $amount, $balanceBefore, $balanceAfter, $traceId);
    }

    /**
     * Insert a ledger row and return the shape exposed to clients.
     *
     * @return array{transaction_id: string, type: string, amount: int, balance_before: int, balance_after: int, created_at: string}
     */
    private function record(
        Account $account,
        string $type,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
        string $traceId,
    ): array {
        $createdAt = now();
        $publicId = $this->publicTransactionId($createdAt);

        DB::table('transactions')->insert([
            'id' => (string) Str::uuid7(),
            'public_transaction_id' => $publicId,
            'account_id' => $account->getKey(),
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'trace_id' => $traceId,
            'created_at' => $createdAt,
        ]);

        return [
            'transaction_id' => $publicId,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'created_at' => $createdAt->toIso8601String(),
        ];
    }

    /**
     * Build the client-facing identifier shown on receipts: ddmmyy-xxxx-xxxxxx.
     */
    private function publicTransactionId(DateTimeInterface $createdAt): string
    {
        return $createdAt->format(self::PUBLIC_ID_DATE_FORMAT)
            .'-'.Str::random(self::PUBLIC_ID_GROUP_LENGTH)
            .'-'.Str::random(self::PUBLIC_ID_SUFFIX_LENGTH);
    }
}
