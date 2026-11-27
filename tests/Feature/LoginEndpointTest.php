<?php

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns a bearer token for valid credentials', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_in', 3600);

    expect(app(JwtService::class)->parse($response->json('data.access_token'))['sub'])->toBe($user->id);
});

it('rejects invalid credentials', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
});

it('validates the login payload', function (): void {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED')
        ->assertJsonCount(2, 'errors');
});

it('validates each login field', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email', 'password' => 'secret'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'email');

    $this->postJson('/api/v1/auth/login', ['email' => 'member@example.test'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'password');
});

it('audits a rejected payload when the email identifies a user', function (): void {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email])
        ->assertStatus(422);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('login')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('validation_failed')
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->trace_id)->toBe(DB::table('request_logs')->sole()->trace_id);
});

it('skips the validation audit for an unknown email', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test'])
        ->assertStatus(422);

    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('rejects a soft deleted account', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);
    $user->delete();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
});

it('throttles repeated login attempts', function (): void {
    $payload = ['email' => 'nobody@example.test', 'password' => 'wrong-password'];

    foreach (sendLoginAttempts(5, $payload) as $response) {
        $response->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', $payload)
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '5')
        ->assertHeader('X-RateLimit-Remaining', '0');
});

it('allows attempts once the throttle window resets', function (): void {
    $payload = ['email' => 'nobody@example.test', 'password' => 'wrong-password'];

    sendLoginAttempts(6, $payload);

    $this->travel(61)->seconds();

    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
});

it('throttles each client ip independently', function (): void {
    $payload = ['email' => 'nobody@example.test', 'password' => 'wrong-password'];

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9']);

    foreach (sendLoginAttempts(5, $payload) as $response) {
        $response->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10']);

    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
});
