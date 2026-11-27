<?php

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware('auth.jwt')->get('/api/v1/test-me', fn (Request $request) => ApiResponse::success([
        'user_id' => $request->user()->id,
    ]));
});

it('rejects a request without a bearer token', function (): void {
    $this->getJson('/api/v1/test-me')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_MISSING');
});

it('rejects a non bearer authorization scheme', function (): void {
    $this->withHeader('Authorization', 'Basic dXNlcjpwYXNz')
        ->getJson('/api/v1/test-me')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_MISSING');
});

it('rejects a malformed token', function (): void {
    $this->withHeader('Authorization', 'Bearer not-a-token')
        ->getJson('/api/v1/test-me')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_INVALID');
});

it('rejects a token signed with another secret', function (): void {
    $foreign = new JwtService('another-secret', 3600);

    $this->withHeader('Authorization', 'Bearer '.$foreign->issue('user-1')['token'])
        ->getJson('/api/v1/test-me')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_INVALID');
});

it('rejects an expired token', function (): void {
    $user = User::factory()->create();
    $expired = new JwtService(config('jwt.secret'), -1);

    $this->withHeader('Authorization', 'Bearer '.$expired->issue($user->id)['token'])
        ->getJson('/api/v1/test-me')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_EXPIRED');
});

it('audits an expired token against its user', function (): void {
    Route::middleware(['api', 'auth.jwt'])->get('/api/v1/test-me-logged', fn () => ApiResponse::success());

    $user = User::factory()->create();
    $expired = new JwtService(config('jwt.secret'), -1);

    $this->withHeader('Authorization', 'Bearer '.$expired->issue($user->id)['token'])
        ->getJson('/api/v1/test-me-logged')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_EXPIRED');

    $audit = DB::table('audit_logs')->sole();

    expect($audit->action)->toBe('authenticate')
        ->and($audit->result)->toBe('failure')
        ->and($audit->failure_reason)->toBe('token_expired')
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->trace_id)->toBe(DB::table('request_logs')->sole()->trace_id);

    expect(DB::table('request_logs')->sole()->user_id)->toBe($user->id);
});

it('skips the audit when an expired token belongs to a deleted user', function (): void {
    Route::middleware(['api', 'auth.jwt'])->get('/api/v1/test-me-logged', fn () => ApiResponse::success());

    $user = User::factory()->create();
    $token = (new JwtService(config('jwt.secret'), -1))->issue($user->id)['token'];
    $user->delete();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/test-me-logged')
        ->assertStatus(401);

    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('rejects a token whose user no longer exists', function (): void {
    $user = User::factory()->create();
    $token = app(JwtService::class)->issue($user->id)['token'];
    $user->delete();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/test-me')
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'TOKEN_INVALID');
});

it('binds the token user to the request', function (): void {
    $user = User::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.app(JwtService::class)->issue($user->id)['token'])
        ->getJson('/api/v1/test-me')
        ->assertOk()
        ->assertJsonPath('data.user_id', $user->id);
});
