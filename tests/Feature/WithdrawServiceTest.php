<?php

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\RequestLogRepository;
use App\Repositories\TransactionRepository;
use App\Services\WithdrawService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Seed an in-flight idempotency claim so the service can complete it.
 */
function seedWithdrawClaim(string $traceId, User $user): void
{
    DB::table('request_logs')->insert([
        'id' => $traceId,
        'trace_id' => $traceId,
        'user_id' => $user->id,
        'method' => 'POST',
        'route' => 'api/v1/transactions/withdraw',
        'operation' => AuditLogRepository::ACTION_WITHDRAW,
        'idempotency_key' => 'test-key',
        'idempotency_status' => RequestLogRepository::IDEMPOTENCY_PROCESSING,
        'request_fingerprint' => hash('sha256', $traceId),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('debits the account and records the ledger entry', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 150000]);
    $traceId = (string) Str::uuid7();
    seedWithdrawClaim($traceId, $user);

    $result = app(WithdrawService::class)->withdraw($user, 100000, $traceId);

    expect($result['balance'])->toBe(50000)
        ->and($result['transaction']['transaction_id'])->toMatch('/^\d{6}-[A-Za-z0-9]{4}-[A-Za-z0-9]{6}$/')
        ->and($result['transaction']['type'])->toBe('withdraw')
        ->and($result['transaction']['amount'])->toBe(100000)
        ->and($result['transaction']['balance_before'])->toBe(150000)
        ->and($result['transaction']['balance_after'])->toBe(50000);

    expect(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(50000);

    $ledger = DB::table('transactions')->sole();

    expect($ledger->account_id)->toBe($account->id)
        ->and($ledger->trace_id)->toBe($traceId)
        ->and($ledger->public_transaction_id)->toBe($result['transaction']['transaction_id'])
        ->and($ledger->balance_before)->toBe(150000)
        ->and($ledger->balance_after)->toBe(50000);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('withdraw')
        ->and($audit->result)->toBe('success')
        ->and($audit->failure_reason)->toBeNull()
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->subject_type)->toBe(Account::class)
        ->and($audit->subject_id)->toBe($account->id)
        ->and($audit->trace_id)->toBe($traceId);

    expect(DB::table('request_logs')->where('id', $traceId)->value('idempotency_status'))->toBe('completed');
});

it('allows withdrawing the full balance', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);
    $traceId = (string) Str::uuid7();
    seedWithdrawClaim($traceId, $user);

    $result = app(WithdrawService::class)->withdraw($user, 50000, $traceId);

    expect($result['balance'])->toBe(0)
        ->and(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(0);
});

it('rejects a withdrawal that exceeds the balance', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);
    $traceId = (string) Str::uuid7();
    seedWithdrawClaim($traceId, $user);

    try {
        app(WithdrawService::class)->withdraw($user, 100000, $traceId);
        $this->fail('Withdrawal accepted an amount above the balance.');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::InsufficientBalance)
            ->and($exception->getMessage())->toBe('Insufficient balance.')
            ->and($exception->errors)->toHaveCount(1)
            ->and($exception->errors[0]['field'])->toBe('amount');
    }

    expect(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(50000)
        ->and(DB::table('transactions')->count())->toBe(0);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('withdraw')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('insufficient_balance')
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->subject_type)->toBe(Account::class)
        ->and($audit->subject_id)->toBe($account->id);

    expect(DB::table('request_logs')->where('id', $traceId)->value('idempotency_status'))->toBe('processing');
});

it('rejects a user without an account', function (): void {
    $user = User::factory()->create();
    $traceId = (string) Str::uuid7();
    seedWithdrawClaim($traceId, $user);

    try {
        app(WithdrawService::class)->withdraw($user, 1000, $traceId);
        $this->fail('Withdrawal accepted a user without an account.');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::NotFound)
            ->and($exception->getMessage())->toBe('The authenticated user has no account.');
    }

    expect(DB::table('transactions')->count())->toBe(0);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('withdraw')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('account_not_found')
        ->and($audit->subject_type)->toBe(User::class);

    expect(DB::table('request_logs')->where('id', $traceId)->value('idempotency_status'))->toBe('processing');
});

it('rolls back every write when the ledger insert fails', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 150000]);
    $otherAccount = Account::factory()->create(['user_id' => User::factory()->create()->id]);
    $traceId = (string) Str::uuid7();
    seedWithdrawClaim($traceId, $user);

    $this->travelTo('2026-11-27 08:00:00');

    $collidingPublicId = '271126'
        .'-'.str_repeat('a', TransactionRepository::PUBLIC_ID_GROUP_LENGTH)
        .'-'.str_repeat('b', TransactionRepository::PUBLIC_ID_SUFFIX_LENGTH);

    DB::table('transactions')->insert([
        'id' => (string) Str::uuid7(),
        'public_transaction_id' => $collidingPublicId,
        'account_id' => $otherAccount->id,
        'type' => TransactionRepository::TYPE_WITHDRAW,
        'amount' => 1,
        'balance_before' => 1,
        'balance_after' => 0,
        'trace_id' => 'colliding-trace',
        'created_at' => now(),
    ]);

    Str::createRandomStringsUsing(fn (int $length) => $length === TransactionRepository::PUBLIC_ID_GROUP_LENGTH ? 'aaaa' : 'bbbbbb');

    try {
        app(WithdrawService::class)->withdraw($user, 100000, $traceId);
        $this->fail('Withdrawal accepted a duplicate public transaction id.');
    } catch (QueryException) {
    } finally {
        Str::createRandomStringsNormally();
    }

    expect(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(150000)
        ->and(DB::table('transactions')->count())->toBe(1)
        ->and(DB::table('audit_logs')->count())->toBe(0)
        ->and(DB::table('request_logs')->where('id', $traceId)->value('idempotency_status'))->toBe('processing');
});

it('rolls back when the idempotency claim was taken over', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 150000]);
    $traceId = (string) Str::uuid7();
    seedWithdrawClaim($traceId, $user);

    DB::table('request_logs')->where('id', $traceId)->update([
        'idempotency_key' => null,
        'idempotency_status' => 'failed',
    ]);

    expect(fn () => app(WithdrawService::class)->withdraw($user, 100000, $traceId))
        ->toThrow(RuntimeException::class);

    expect(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(150000)
        ->and(DB::table('transactions')->count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe(0);
});

it('rejects amounts outside the allowed range', function (int $amount): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);
    $traceId = (string) Str::uuid7();
    seedWithdrawClaim($traceId, $user);

    try {
        app(WithdrawService::class)->withdraw($user, $amount, $traceId);
        $this->fail('Withdrawal accepted an amount outside the allowed range.');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::ValidationFailed)
            ->and($exception->errors)->toHaveCount(1)
            ->and($exception->errors[0]['field'])->toBe('amount');
    }

    expect(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(50000)
        ->and(DB::table('transactions')->count())->toBe(0);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('withdraw')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('validation_failed')
        ->and($audit->user_id)->toBe($user->id);
})->with([0, -1, WithdrawService::MAX_AMOUNT + 1]);

it('accepts the amount boundaries', function (int $amount): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => WithdrawService::MAX_AMOUNT]);
    $traceId = (string) Str::uuid7();
    seedWithdrawClaim($traceId, $user);

    $result = app(WithdrawService::class)->withdraw($user, $amount, $traceId);

    expect($result['balance'])->toBe(WithdrawService::MAX_AMOUNT - $amount);
})->with([WithdrawService::MIN_AMOUNT, WithdrawService::MAX_AMOUNT]);

it('audits a validation failure for an identified user', function (): void {
    $user = User::factory()->create();

    app(WithdrawService::class)->recordValidationFailure($user, 'validation-trace');

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('withdraw')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('validation_failed')
        ->and($audit->user_id)->toBe($user->id);
});
