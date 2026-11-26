<?php

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidTokenException;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('wraps a successful payload in the envelope', function (): void {
    $response = ApiResponse::success(['balance' => 1000], 'Balance retrieved.');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'success' => true,
            'message' => 'Balance retrieved.',
            'data' => ['balance' => 1000],
            'errors' => [],
        ]);
});

it('wraps unknown api routes as not found', function (): void {
    $this->getJson('/api/v1/missing')
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('data', null)
        ->assertJsonPath('errors.0.code', 'NOT_FOUND');
});

it('wraps disallowed methods', function (): void {
    Route::post('/api/v1/test-method', fn () => ApiResponse::success());

    $this->getJson('/api/v1/test-method')
        ->assertStatus(405)
        ->assertJsonPath('errors.0.code', 'METHOD_NOT_ALLOWED');
});

it('maps validation failures to per-field error entries', function (): void {
    Route::post('/api/v1/test-validation', fn (Request $request) => $request->validate([
        'email' => ['required', 'email'],
    ]));

    $this->postJson('/api/v1/test-validation', [])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED')
        ->assertJsonPath('errors.0.field', 'email');
});

it('renders domain rejections as api exceptions', function (): void {
    Route::get('/api/v1/test-api-exception', fn () => throw new ApiException(
        ApiErrorCode::NotFound,
        message: 'Account does not exist.',
    ));

    $this->getJson('/api/v1/test-api-exception')
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NOT_FOUND')
        ->assertJsonPath('message', 'Account does not exist.');
});

it('maps token rejections to invalid and expired codes', function (): void {
    Route::get('/api/v1/test-token-invalid', fn () => throw InvalidTokenException::signatureInvalid());
    Route::get('/api/v1/test-token-expired', fn () => throw InvalidTokenException::expired());

    $this->getJson('/api/v1/test-token-invalid')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_INVALID');

    $this->getJson('/api/v1/test-token-expired')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_EXPIRED');
});

it('maps unauthenticated requests to unauthorized', function (): void {
    Route::get('/api/v1/test-authentication', fn () => throw new AuthenticationException);

    $this->getJson('/api/v1/test-authentication')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'UNAUTHORIZED');
});

it('maps throttled requests to rate limited', function (): void {
    Route::middleware('throttle:2,1')->get('/api/v1/test-throttle', fn () => ApiResponse::success());

    $this->getJson('/api/v1/test-throttle')->assertOk();
    $this->getJson('/api/v1/test-throttle')->assertOk();

    $this->getJson('/api/v1/test-throttle')
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'RATE_LIMITED');
});

it('preserves the status of http exceptions', function (): void {
    Route::get('/api/v1/test-forbidden', fn () => abort(403));
    Route::get('/api/v1/test-conflict', fn () => abort(409));
    Route::get('/api/v1/test-unavailable', fn () => abort(503));

    $this->getJson('/api/v1/test-forbidden')
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');

    $this->getJson('/api/v1/test-conflict')
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'CONFLICT');

    $this->getJson('/api/v1/test-unavailable')
        ->assertStatus(503)
        ->assertJsonPath('errors.0.code', 'SERVICE_UNAVAILABLE');
});

it('hides unexpected exception details behind internal error', function (): void {
    Route::get('/api/v1/test-error', fn () => throw new RuntimeException('database password leaked'));

    $this->getJson('/api/v1/test-error')
        ->assertStatus(500)
        ->assertJsonPath('errors.0.code', 'INTERNAL_ERROR')
        ->assertJsonPath('message', 'An unexpected error occurred.')
        ->assertDontSee('database password leaked');
});
