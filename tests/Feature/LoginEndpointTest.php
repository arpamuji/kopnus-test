<?php

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('throttles repeated login attempts', function (): void {
    $payload = ['email' => 'nobody@example.test', 'password' => 'wrong-password'];

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', $payload)
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'RATE_LIMITED');
});
