<?php

use App\Models\Account;
use App\Models\User;
use App\Services\JwtService;
use App\Services\WithdrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Issue a bearer token for the given user.
 */
function withdrawBearerToken(User $user): string
{
    return app(JwtService::class)->issue($user->id)['token'];
}

it('debits the account and returns the nested envelope', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 150000]);

    $response = $this->withHeader('Authorization', 'Bearer '.withdrawBearerToken($user))
        ->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 100000]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Withdrawal successful.')
        ->assertJsonPath('errors', [])
        ->assertJsonPath('data.balance', 50000)
        ->assertJsonPath('data.transaction.type', 'withdraw')
        ->assertJsonPath('data.transaction.amount', 100000)
        ->assertJsonPath('data.transaction.balance_before', 150000)
        ->assertJsonPath('data.transaction.balance_after', 50000);

    expect($response->json('data.transaction.transaction_id'))
        ->toMatch('/^\d{6}-[A-Za-z0-9]{4}-[A-Za-z0-9]{6}$/');

    expect(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(50000)
        ->and(DB::table('transactions')->count())->toBe(1);
});

it('logs the withdrawal request with its idempotency outcome', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => 150000]);

    $response = $this->withHeader('Authorization', 'Bearer '.withdrawBearerToken($user))
        ->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 100000])
        ->assertStatus(201);

    $log = DB::table('request_logs')->sole();

    expect($log->operation)->toBe('withdraw')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->status_code)->toBe(201)
        ->and($log->outcome)->toBe('success')
        ->and($log->idempotency_key)->toBe('withdraw-key-1')
        ->and($log->idempotency_status)->toBe('completed')
        ->and($log->request_fingerprint)->toHaveLength(64)
        ->and(json_decode((string) $log->response_payload, true))->toBe($response->json());
});

it('rejects a request without a bearer token', function (): void {
    $this->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 100000])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_MISSING');

    expect(DB::table('transactions')->count())->toBe(0);
});

it('rejects a request with an invalid bearer token', function (): void {
    $this->withHeader('Authorization', 'Bearer not-a-token')
        ->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 100000])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_INVALID');
});

it('rejects invalid amounts', function (mixed $amount, string $field): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);

    $payload = $amount === null ? [] : ['amount' => $amount];

    $this->withHeader('Authorization', 'Bearer '.withdrawBearerToken($user))
        ->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', $payload)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', $field)
        ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');

    expect(DB::table('transactions')->count())->toBe(0);
})->with([
    'missing' => [null, 'amount'],
    'zero' => [0, 'amount'],
    'negative' => [-1, 'amount'],
    'fractional' => [1000.5, 'amount'],
    'above cap' => [WithdrawService::MAX_AMOUNT + 1, 'amount'],
]);

it('accepts the amount boundaries', function (int $amount): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => WithdrawService::MAX_AMOUNT]);

    $this->withHeader('Authorization', 'Bearer '.withdrawBearerToken($user))
        ->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => $amount])
        ->assertStatus(201)
        ->assertJsonPath('data.balance', WithdrawService::MAX_AMOUNT - $amount);
})->with([WithdrawService::MIN_AMOUNT, WithdrawService::MAX_AMOUNT]);

it('rejects a request without an idempotency key', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);

    $this->withHeader('Authorization', 'Bearer '.withdrawBearerToken($user))
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 10000])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'Idempotency-Key')
        ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');

    expect(DB::table('request_logs')->sole()->idempotency_key)->toBeNull()
        ->and(DB::table('transactions')->count())->toBe(0);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('withdraw')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('validation_failed');
});

it('rejects a user without an account', function (): void {
    $user = User::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.withdrawBearerToken($user))
        ->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 10000])
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NOT_FOUND');

    expect(DB::table('transactions')->count())->toBe(0);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('withdraw')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('account_not_found')
        ->and($audit->user_id)->toBe($user->id);

    expect(DB::table('request_logs')->sole()->idempotency_status)->toBe('failed');
});

it('rejects a withdrawal above the balance', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);

    $this->withHeader('Authorization', 'Bearer '.withdrawBearerToken($user))
        ->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 100000])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_BALANCE')
        ->assertJsonPath('errors.0.field', 'amount');

    expect(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(50000)
        ->and(DB::table('transactions')->count())->toBe(0);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('withdraw')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('insufficient_balance')
        ->and($audit->subject_type)->toBe(Account::class)
        ->and($audit->subject_id)->toBe($account->id);

    $log = DB::table('request_logs')->sole();

    expect($log->status_code)->toBe(422)
        ->and($log->idempotency_key)->toBe('withdraw-key-1')
        ->and($log->idempotency_status)->toBe('failed')
        ->and($log->response_payload)->not->toBeNull();
});

it('rejects a second withdrawal that would overdraw the account', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
    $token = withdrawBearerToken($user);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->withHeader('Idempotency-Key', 'withdraw-key-1')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 1000])
        ->assertStatus(201)
        ->assertJsonPath('data.balance', 0);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->withHeader('Idempotency-Key', 'withdraw-key-2')
        ->postJson('/api/v1/transactions/withdraw', ['amount' => 1000])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_BALANCE');

    expect(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(0)
        ->and(DB::table('transactions')->count())->toBe(1);
});
