<?php

use App\Http\Middleware\EnsureIdempotentRequest;
use App\Models\Account;
use App\Models\User;
use App\Repositories\RequestLogRepository;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Send a deposit request authenticated as the given user.
 */
function sendDeposit(User $user, string $key, array $payload): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.app(JwtService::class)->issue($user->id)['token'])
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/transactions/deposit', $payload);
}

/**
 * Reset a claimed key to processing with no stored response.
 */
function markClaimProcessing(string $key): void
{
    DB::table('request_logs')->where('idempotency_key', $key)->update([
        'idempotency_status' => RequestLogRepository::IDEMPOTENCY_PROCESSING,
        'status_code' => null,
        'response_payload' => null,
        'updated_at' => now(),
    ]);
}

it('replays the stored response for the same key and payload', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id]);

    $first = sendDeposit($user, 'replay-key', ['amount' => 100000])->assertStatus(201);
    $second = sendDeposit($user, 'replay-key', ['amount' => 100000])->assertStatus(201);

    expect($second->getContent())->toBe($first->getContent())
        ->and(DB::table('transactions')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'deposit')->count())->toBe(1);

    $logs = DB::table('request_logs')->orderBy('created_at')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[1]->idempotency_key)->toBeNull()
        ->and($logs[1]->idempotency_status)->toBe('replayed')
        ->and($logs[1]->status_code)->toBe(201);
});

it('rejects the same key with a different payload', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id]);

    sendDeposit($user, 'conflict-key', ['amount' => 100000])->assertStatus(201);

    sendDeposit($user, 'conflict-key', ['amount' => 200000])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'CONFLICT')
        ->assertJsonPath('errors.0.field', 'Idempotency-Key');

    expect(DB::table('transactions')->count())->toBe(1);
});

it('rejects a fractional amount reusing a used key', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id]);

    sendDeposit($user, 'fraction-key', ['amount' => 100000])->assertStatus(201);

    sendDeposit($user, 'fraction-key', ['amount' => 100000.5])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'CONFLICT');

    expect(DB::table('transactions')->count())->toBe(1);
});

it('rejects a duplicate while the first request is still processing', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id]);

    sendDeposit($user, 'processing-key', ['amount' => 100000])->assertStatus(201);

    markClaimProcessing('processing-key');

    sendDeposit($user, 'processing-key', ['amount' => 100000])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'CONFLICT');

    expect(DB::table('transactions')->count())->toBe(1);
});

it('reclaims a stale processing claim', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    sendDeposit($user, 'stale-key', ['amount' => 100000])->assertStatus(201);

    markClaimProcessing('stale-key');

    $this->travel(EnsureIdempotentRequest::PROCESSING_TIMEOUT_SECONDS + 1)->seconds();

    sendDeposit($user, 'stale-key', ['amount' => 100000])->assertStatus(201);

    $stale = DB::table('request_logs')->where('error_type', RequestLogRepository::ERROR_IDEMPOTENCY_TIMEOUT)->sole();

    expect($stale->idempotency_key)->toBeNull()
        ->and($stale->idempotency_status)->toBe('failed')
        ->and(DB::table('transactions')->count())->toBe(2)
        ->and(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(200000);
});

it('replays a failed response without executing again', function (): void {
    $user = User::factory()->create();

    $first = sendDeposit($user, 'failed-key', ['amount' => 100000])->assertStatus(404);
    $second = sendDeposit($user, 'failed-key', ['amount' => 100000])->assertStatus(404);

    expect($second->getContent())->toBe($first->getContent())
        ->and(DB::table('transactions')->count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe(1);

    expect(DB::table('request_logs')->orderBy('created_at')->get()->last()->idempotency_status)->toBe('replayed');
});

it('scopes the key to the authenticated user', function (): void {
    $firstUser = User::factory()->create();
    Account::factory()->create(['user_id' => $firstUser->id]);
    $secondUser = User::factory()->create();
    Account::factory()->create(['user_id' => $secondUser->id]);

    sendDeposit($firstUser, 'shared-key', ['amount' => 100000])->assertStatus(201);
    sendDeposit($secondUser, 'shared-key', ['amount' => 100000])->assertStatus(201);

    expect(DB::table('transactions')->count())->toBe(2);
});

it('releases the key when validation fails', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id]);

    sendDeposit($user, 'retry-key', ['amount' => 0])->assertStatus(422);

    expect(DB::table('request_logs')->sole()->idempotency_key)->toBeNull();

    sendDeposit($user, 'retry-key', ['amount' => 1000])->assertStatus(201);

    expect(DB::table('transactions')->count())->toBe(1);
});
