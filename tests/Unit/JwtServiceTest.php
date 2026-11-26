<?php

use App\Exceptions\InvalidTokenException;
use App\Services\JwtService;

beforeEach(function (): void {
    $this->secret = 'unit-test-secret';
    $this->jwt = new JwtService($this->secret, 3600);
});

it('issues a token carrying the subject and expiry claims', function (): void {
    $issued = $this->jwt->issue('user-1');
    $claims = $this->jwt->parse($issued['token']);

    expect($claims['sub'])->toBe('user-1')
        ->and($claims['exp'])->toBe($issued['expires_at'])
        ->and($issued['expires_in'])->toBe(3600)
        ->and($issued['expires_at'])->toBeGreaterThan(time());
});

it('rejects a token without three segments', function (): void {
    $this->jwt->parse('not-a-token');
})->throws(InvalidTokenException::class, 'Token structure is invalid.');

it('rejects a token whose payload was replaced', function (): void {
    [$header, , $signature] = explode('.', $this->jwt->issue('user-1')['token']);
    $forged = rtrim(strtr(base64_encode((string) json_encode([
        'sub' => 'user-2',
        'iat' => time(),
        'exp' => time() + 3600,
    ])), '+/', '-_'), '=');

    $this->jwt->parse($header.'.'.$forged.'.'.$signature);
})->throws(InvalidTokenException::class, 'Token signature is invalid.');

it('rejects a token signed with a different secret', function (): void {
    $foreign = new JwtService('another-secret', 3600);

    $this->jwt->parse($foreign->issue('user-1')['token']);
})->throws(InvalidTokenException::class, 'Token signature is invalid.');

it('rejects an expired token', function (): void {
    $expired = new JwtService($this->secret, -1);

    $expired->parse($expired->issue('user-1')['token']);
})->throws(InvalidTokenException::class, 'Token has expired.');

it('carries the subject of an expired token', function (): void {
    $expired = new JwtService($this->secret, -1);

    try {
        $expired->parse($expired->issue('user-1')['token']);
    } catch (InvalidTokenException $exception) {
        expect($exception->subject)->toBe('user-1');

        return;
    }

    throw new RuntimeException('Expired token was accepted.');
});

it('refuses to sign without a secret', function (): void {
    new JwtService('', 3600);
})->throws(InvalidArgumentException::class);
