<?php

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\JwtService;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Wraps a real hasher and records how AuthService uses it.
 */
final class RecordingHasher implements Hasher
{
    public ?string $madeHash = null;

    public int $makes = 0;

    /** @var list<string> */
    public array $checkedHashes = [];

    public function __construct(private readonly Hasher $inner) {}

    public function info($hashedValue): array
    {
        return $this->inner->info($hashedValue);
    }

    public function make($value, array $options = []): string
    {
        $this->makes++;

        return $this->madeHash = $this->inner->make($value, $options);
    }

    public function check($value, $hashedValue, array $options = []): bool
    {
        $this->checkedHashes[] = $hashedValue;

        return $this->inner->check($value, $hashedValue, $options);
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }
}

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
    $claims = app(JwtService::class)->parse($result['token']['access_token']);

    expect($result['token']['token_type'])->toBe('Bearer')
        ->and($result['token']['expires_in'])->toBe(3600)
        ->and($claims['sub'])->toBe($user->id)
        ->and($result['user']->is($user))->toBeTrue();
});

it('rejects a wrong password', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);

    expectInvalidCredentials(fn () => app(AuthService::class)->login($user->email, 'wrong-password', 'test-trace-id'));
});

it('rejects an unknown email', function (): void {
    expectInvalidCredentials(fn () => app(AuthService::class)->login('nobody@example.test', 'password', 'test-trace-id'));
});

it('checks an unknown email against a hash produced by the hasher', function (): void {
    $hasher = new RecordingHasher(app('hash'));

    $service = new AuthService(
        app(UserRepository::class),
        app(JwtService::class),
        $hasher,
        app(AuditLogRepository::class),
        app('cache.store'),
    );

    $attempt = fn () => expectInvalidCredentials(
        fn () => $service->login('nobody@example.test', 'password', 'test-trace-id'),
    );

    $attempt();
    $attempt();

    expect($hasher->madeHash)->not->toBeNull()
        ->and($hasher->makes)->toBe(1)
        ->and(array_unique($hasher->checkedHashes))->toBe([$hasher->madeHash]);
});

it('records no success audit when token issuance fails', function (): void {
    $user = User::factory()->create(['id' => "\xB1", 'password' => 'correct-password']);

    expect(fn () => app(AuthService::class)->login($user->email, 'correct-password', 'test-trace-id'))
        ->toThrow(JsonException::class);

    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('rejects a soft deleted user', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);
    $user->delete();

    expectInvalidCredentials(fn () => app(AuthService::class)->login($user->email, 'correct-password', 'test-trace-id'));
});
