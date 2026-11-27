<?php

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\RecordApiRequest;
use App\Models\Account;
use App\Models\User;
use App\Repositories\RequestLogRepository;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/**
 * Send a withdrawal request authenticated as the given user.
 */
function sendWithdrawal(User $user, string $key, array $payload): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.app(JwtService::class)->issue($user->id)['token'])
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/transactions/withdraw', $payload);
}

/**
 * Reset a claimed key to processing with no stored response.
 */
function markWithdrawClaimProcessing(string $key): void
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
    Account::factory()->create(['user_id' => $user->id, 'balance' => 200000]);

    $first = sendWithdrawal($user, 'replay-key', ['amount' => 100000])->assertStatus(201);
    $second = sendWithdrawal($user, 'replay-key', ['amount' => 100000])->assertStatus(201);

    expect($second->getContent())->toBe($first->getContent())
        ->and(DB::table('transactions')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'withdraw')->count())->toBe(1);

    $logs = DB::table('request_logs')->orderBy('created_at')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[1]->idempotency_key)->toBeNull()
        ->and($logs[1]->idempotency_status)->toBe('replayed')
        ->and($logs[1]->status_code)->toBe(201);
});

it('rejects the same key with a different payload', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => 200000]);

    sendWithdrawal($user, 'conflict-key', ['amount' => 100000])->assertStatus(201);

    sendWithdrawal($user, 'conflict-key', ['amount' => 20000])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'CONFLICT')
        ->assertJsonPath('errors.0.field', 'Idempotency-Key');

    expect(DB::table('transactions')->count())->toBe(1);
});

it('rejects a fractional amount reusing a used key', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => 200000]);

    sendWithdrawal($user, 'fraction-key', ['amount' => 100000])->assertStatus(201);

    sendWithdrawal($user, 'fraction-key', ['amount' => 100000.5])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'CONFLICT');

    expect(DB::table('transactions')->count())->toBe(1);
});

it('rejects a duplicate while the first request is still processing', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => 200000]);

    sendWithdrawal($user, 'processing-key', ['amount' => 100000])->assertStatus(201);

    markWithdrawClaimProcessing('processing-key');

    sendWithdrawal($user, 'processing-key', ['amount' => 100000])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'CONFLICT');

    expect(DB::table('transactions')->count())->toBe(1);
});

it('reclaims a stale processing claim', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 200000]);

    sendWithdrawal($user, 'stale-key', ['amount' => 100000])->assertStatus(201);

    markWithdrawClaimProcessing('stale-key');

    $this->travel(EnsureIdempotentRequest::PROCESSING_TIMEOUT_SECONDS + 1)->seconds();

    sendWithdrawal($user, 'stale-key', ['amount' => 100000])->assertStatus(201);

    $stale = DB::table('request_logs')->where('error_type', RequestLogRepository::ERROR_IDEMPOTENCY_TIMEOUT)->sole();

    expect($stale->idempotency_key)->toBeNull()
        ->and($stale->idempotency_status)->toBe('failed')
        ->and(DB::table('transactions')->count())->toBe(2)
        ->and(DB::table('accounts')->where('id', $account->id)->value('balance'))->toBe(0);
});

it('replays an insufficient balance response without executing again', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);

    $first = sendWithdrawal($user, 'insufficient-key', ['amount' => 100000])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_BALANCE');

    $second = sendWithdrawal($user, 'insufficient-key', ['amount' => 100000])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_BALANCE');

    expect($second->getContent())->toBe($first->getContent())
        ->and(DB::table('transactions')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'withdraw')->count())->toBe(1);

    $log = DB::table('request_logs')->orderBy('created_at')->first();

    expect($log->idempotency_key)->toBe('insufficient-key')
        ->and($log->idempotency_status)->toBe('failed');

    sendWithdrawal($user, 'insufficient-key', ['amount' => 10000])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'CONFLICT');

    expect(DB::table('transactions')->count())->toBe(0);
});

it('scopes the key to the authenticated user', function (): void {
    $firstUser = User::factory()->create();
    Account::factory()->create(['user_id' => $firstUser->id, 'balance' => 200000]);
    $secondUser = User::factory()->create();
    Account::factory()->create(['user_id' => $secondUser->id, 'balance' => 200000]);

    sendWithdrawal($firstUser, 'shared-key', ['amount' => 100000])->assertStatus(201);
    sendWithdrawal($secondUser, 'shared-key', ['amount' => 100000])->assertStatus(201);

    expect(DB::table('transactions')->count())->toBe(2);
});

it('releases the key when validation fails', function (): void {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);

    sendWithdrawal($user, 'retry-key', ['amount' => 0])->assertStatus(422);

    expect(DB::table('request_logs')->sole()->idempotency_key)->toBeNull();

    sendWithdrawal($user, 'retry-key', ['amount' => 1000])->assertStatus(201);

    expect(DB::table('transactions')->count())->toBe(1);
});

it('releases the key when the service rejects the amount', function (): void {
    $user = User::factory()->create();
    $traceId = (string) Str::uuid7();

    DB::table('request_logs')->insert([
        'id' => $traceId,
        'trace_id' => $traceId,
        'user_id' => $user->id,
        'method' => 'POST',
        'route' => 'api/v1/transactions/withdraw',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $request = Request::create(
        '/api/v1/transactions/withdraw',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'service-rejection-key'],
        content: json_encode(['amount' => 0], JSON_THROW_ON_ERROR),
    );
    $request->setUserResolver(fn () => $user);
    $request->attributes->set(RecordApiRequest::TRACE_ID_ATTRIBUTE, $traceId);

    $response = new Response('{"success":false}', 422);
    $response->exception = new ApiException(ApiErrorCode::ValidationFailed);

    app(EnsureIdempotentRequest::class)->handle($request, fn (): Response => $response, 'withdraw');

    $log = DB::table('request_logs')->where('id', $traceId)->sole();

    expect($log->idempotency_key)->toBeNull()
        ->and($log->idempotency_status)->toBeNull();
});
