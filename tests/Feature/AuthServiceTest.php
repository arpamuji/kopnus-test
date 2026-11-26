<?php

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuthService;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Attempt login and assert it was rejected without revealing why.
 */
function expectInvalidCredentials(Closure $attempt): void
{
    try {
        $attempt();
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::InvalidCredentials)
            ->and($exception->getMessage())->toBe('Email or password is incorrect.');

        return;
    }

    throw new RuntimeException('Login accepted credentials it should have rejected.');
}

it('issues a bearer token for valid credentials', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    $result = app(AuthService::class)->login($user->email, 'correct-password', 'test-trace-id');
    $claims = app(JwtService::class)->parse($result['access_token']);

    expect($result['token_type'])->toBe('Bearer')
        ->and($result['expires_in'])->toBe(3600)
        ->and($claims['sub'])->toBe($user->id);
});

it('rejects a wrong password', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    expectInvalidCredentials(fn () => app(AuthService::class)->login($user->email, 'wrong-password', 'test-trace-id'));
});

it('rejects an unknown email', function (): void {
    expectInvalidCredentials(fn () => app(AuthService::class)->login('nobody@example.test', 'password', 'test-trace-id'));
});

it('rejects a soft deleted user', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);
    $user->delete();

    expectInvalidCredentials(fn () => app(AuthService::class)->login($user->email, 'correct-password', 'test-trace-id'));
});
