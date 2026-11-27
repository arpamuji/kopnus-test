<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('logs a successful request with its outcome', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ])->assertOk();

    $log = DB::table('request_logs')->sole();

    expect($log->method)->toBe('POST')
        ->and($log->route)->toBe('api/v1/auth/login')
        ->and($log->operation)->toBe('auth.login')
        ->and($log->status_code)->toBe(200)
        ->and($log->outcome)->toBe('success')
        ->and($log->error_type)->toBeNull()
        ->and($log->user_id)->toBe($user->id);
});

it('logs a rejected login attempt', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(401);

    $log = DB::table('request_logs')->sole();

    expect($log->status_code)->toBe(401)
        ->and($log->outcome)->toBe('failure')
        ->and($log->error_type)->toBe('ApiException');
});

it('logs requests that match no route', function (): void {
    $this->getJson('/api/v1/missing')->assertStatus(404);

    $log = DB::table('request_logs')->sole();

    expect($log->route)->toBe('api/v1/missing')
        ->and($log->status_code)->toBe(404)
        ->and($log->outcome)->toBe('failure')
        ->and($log->error_type)->toBe('NotFoundHttpException')
        ->and($log->operation)->toBeNull();
});

it('logs requests with a disallowed method', function (): void {
    $this->getJson('/api/v1/auth/login')->assertStatus(405);

    $log = DB::table('request_logs')->sole();

    expect($log->status_code)->toBe(405)
        ->and($log->outcome)->toBe('failure')
        ->and($log->error_type)->toBe('MethodNotAllowedHttpException')
        ->and($log->operation)->toBeNull();
});

it('ignores requests outside the api prefix', function (): void {
    $this->get('/up')->assertOk();

    expect(DB::table('request_logs')->count())->toBe(0);
});

it('logs a validation failure', function (): void {
    $this->postJson('/api/v1/auth/login', [])->assertStatus(422);

    $log = DB::table('request_logs')->sole();

    expect($log->status_code)->toBe(422)
        ->and($log->outcome)->toBe('failure')
        ->and($log->error_type)->toBe('ValidationException');
});

it('logs throttled requests', function (): void {
    $payload = ['email' => 'nobody@example.test', 'password' => 'wrong-password'];

    sendLoginAttempts(6, $payload);

    $throttled = DB::table('request_logs')->where('status_code', 429);

    expect(DB::table('request_logs')->count())->toBe(6)
        ->and($throttled->exists())->toBeTrue()
        ->and($throttled->value('error_type'))->toBe('ThrottleRequestsException');
});

it('audits a successful login and links it to the request log', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ])->assertOk();

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('login')
        ->and($audit->result)->toBe('success')
        ->and($audit->failure_reason)->toBeNull()
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->trace_id)->toBe(DB::table('request_logs')->sole()->trace_id);
});

it('audits a wrong password against the identified user', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(401);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('invalid_credentials')
        ->and($audit->user_id)->toBe($user->id);
});

it('audits an unknown email without an actor', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.test',
        'password' => 'wrong-password',
    ])->assertStatus(401);

    $audit = DB::table('audit_logs')->sole();

    expect($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('invalid_credentials')
        ->and($audit->user_id)->toBeNull()
        ->and($audit->subject_type)->toBeNull();
});
