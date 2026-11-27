<?php

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Assert the response carries no credential, token, or internal detail.
 */
function expectNoInternals(TestResponse $response): void
{
    $payload = $response->json();
    $messages = implode(' ', array_filter([
        (string) ($payload['message'] ?? ''),
        ...array_map(
            static fn (array $error): string => (string) ($error['message'] ?? ''),
            $payload['errors'] ?? [],
        ),
    ]));

    expect((string) $response->getContent())
        ->not->toContain('sup3r-secret-value')
        ->not->toContain('$2y$')
        ->not->toContain('Bearer ')
        ->not->toContain('Exception');

    expect($messages)
        ->not->toContain('sup3r-secret-value')
        ->not->toContain(base_path())
        ->not->toContain('crash at');
}

it('keeps credentials and internals out of error responses', function (string $method, string $uri, array $payload, int $status): void {
    Route::get('/api/v1/leak-conflict', fn () => abort(409));
    Route::get('/api/v1/leak-crash', fn () => throw new RuntimeException('crash at '.base_path()));

    $response = $this->json($method, $uri, $payload);

    $response->assertStatus($status);
    expectNoInternals($response);
})->with([
    'invalid credentials' => ['POST', '/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'sup3r-secret-value'], 401],
    'validation failure' => ['POST', '/api/v1/auth/login', ['email' => 'not-an-email', 'password' => 'sup3r-secret-value'], 422],
    'not found' => ['GET', '/api/v1/leak-missing', [], 404],
    'method not allowed' => ['GET', '/api/v1/auth/login', [], 405],
    'conflict' => ['GET', '/api/v1/leak-conflict', [], 409],
    'internal error' => ['GET', '/api/v1/leak-crash', [], 500],
]);

it('keeps credentials out of throttle responses', function (): void {
    $payload = ['email' => 'nobody@example.test', 'password' => 'sup3r-secret-value'];

    $responses = sendLoginAttempts(6, $payload);
    $response = end($responses);

    $response->assertStatus(429);
    expectNoInternals($response);
});

it('does not echo a rejected bearer token', function (): void {
    Route::middleware('auth.jwt')->get('/api/v1/leak-protected', fn () => ApiResponse::success());

    $user = User::factory()->create();
    $token = (new JwtService(config('jwt.secret'), -1))->issue($user->id)['token'];

    $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/leak-protected');

    $response->assertStatus(401);
    expect((string) $response->getContent())->not->toContain($token);
});

it('stores no credentials or tokens in request and audit logs', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ])->assertOk()->json('data.access_token');

    foreach (['request_logs', 'audit_logs'] as $table) {
        expect((string) json_encode(DB::table($table)->get()))
            ->not->toContain('correct-password')
            ->not->toContain($token);
    }
});
