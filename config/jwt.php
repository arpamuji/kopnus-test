<?php

$defaultTtlSeconds = 3600;

// Tokens are short-lived because there is no refresh, logout, or revocation path,
// so a leaked token stays usable only until `exp`.
return [
    'secret' => (string) env('JWT_SECRET', ''),
    'ttl_seconds' => (int) env('JWT_TTL', $defaultTtlSeconds),
];
