# KOPNUS API

Laravel 13 API for member financial transactions. Amounts are whole Indonesian rupiah (IDR) integers. Writes are authenticated with a short-lived HS256 bearer token and are idempotent.

## Requirements

- PHP 8.4 with the `pdo_sqlite` extension
- Composer 2

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `JWT_SECRET` in `.env`; tokens cannot be issued without it:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Create the SQLite database and run migrations:

```bash
touch database/database.sqlite
php artisan migrate
```

Optional demo data creates 10 users with 1,000,000 IDR each. Seeded users share the password `password`:

```bash
php artisan db:seed
php artisan tinker --execute 'App\Models\User::pluck("email");'
```

`composer setup` runs install, env copy, key generation, and migrations, but does not create `database/database.sqlite` or set `JWT_SECRET`, so both steps above are still required.

## Running

```bash
php artisan serve
```

The API listens on http://localhost:8000 under `/api/v1`. `GET /up` is the health check.

`composer dev` starts the server, a queue listener, and log tailing in one command.

## API

Every response uses one envelope:

```json
{
  "success": true,
  "message": "Authenticated.",
  "data": {},
  "errors": []
}
```

`POST /api/v1/auth/login` accepts `email` and `password` and returns `access_token`, `token_type`, and `expires_in`. Login allows 5 attempts per minute per IP. Send the token as `Authorization: Bearer <token>` on the endpoints below.

| Method | Path | Description |
| --- | --- | --- |
| POST | `/api/v1/auth/login` | Exchange credentials for a bearer token |
| POST | `/api/v1/transactions/deposit` | Credit the authenticated account |
| POST | `/api/v1/transactions/withdraw` | Debit the authenticated account |

Deposit and withdraw require a unique `Idempotency-Key` header. Repeating a key with the same payload returns the stored response without executing again; reusing a key with a different payload returns `409 CONFLICT`. Amounts are integers from 1 to 25,000,000. A withdrawal above the available balance returns `422 INSUFFICIENT_BALANCE` and leaves the balance unchanged.

```bash
curl -s http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"member@example.test","password":"password"}'

curl -s http://localhost:8000/api/v1/transactions/withdraw \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: demo-key-1' \
  -d '{"amount":10000}'
```

## Tests

Feature tests run against an in-memory SQLite database configured in `phpunit.xml`, so no setup is needed:

```bash
composer test
```

Equivalent commands:

```bash
php artisan test --compact
vendor/bin/pest
vendor/bin/pest tests/Feature/WithdrawEndpointTest.php
```

Format changed PHP files:

```bash
vendor/bin/pint --dirty --format agent
```

## OpenAPI

The generated spec is committed as `openapi.json`. Regenerate it after changing controller annotations:

```bash
vendor/bin/openapi app -o openapi.json
```

## Architecture

- Controllers handle HTTP only; services own business rules and `DB::transaction` boundaries; repositories own persistence.
- `RecordApiRequest` logs every API request; `AuthenticateWithToken` validates the bearer token; `EnsureIdempotentRequest` enforces idempotency key semantics.
- Tables: `users`, `accounts`, `transactions` (ledger), `audit_logs`, and `request_logs`.
- Withdrawals use a conditional SQL `UPDATE ... WHERE balance >= amount`, so concurrent requests cannot drive the balance below zero.
