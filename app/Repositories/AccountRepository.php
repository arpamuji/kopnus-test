<?php

namespace App\Repositories;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Persistence access for accounts.
 *
 * Balance mutations stay atomic in SQL: SQLite ignores row locks, so a
 * read-modify-write in PHP could lose a concurrent credit.
 */
final class AccountRepository
{
    /**
     * Find the account owned by a user, locking the row where supported.
     */
    public function findByUserIdForUpdate(string $userId): ?Account
    {
        return Account::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Add an amount to the account balance and return the new balance.
     */
    public function credit(Account $account, int $amount): int
    {
        DB::table('accounts')->where('id', $account->getKey())->update([
            // Integer cast keeps the expression safe; the query builder does not
            // bind placeholders used inside raw update values.
            'balance' => DB::raw('balance + '.(int) $amount),
            'updated_at' => now(),
        ]);

        return (int) DB::table('accounts')->where('id', $account->getKey())->value('balance');
    }

    /**
     * Subtract an amount only when the balance covers it, returning the new
     * balance or null when the balance is insufficient.
     *
     * @param  Account  $account  Account to debit.
     * @param  int  $amount  Whole-rupiah amount to subtract.
     */
    public function debit(Account $account, int $amount): ?int
    {
        $affected = DB::table('accounts')
            ->where('id', $account->getKey())
            ->where('balance', '>=', $amount)
            ->update([
                'balance' => DB::raw('balance - '.(int) $amount),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return null;
        }

        return (int) DB::table('accounts')->where('id', $account->getKey())->value('balance');
    }
}
