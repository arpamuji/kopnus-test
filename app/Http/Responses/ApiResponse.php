<?php

namespace App\Http\Responses;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the single JSON envelope shared by every API response.
 */
final class ApiResponse
{
    /**
     * Wrap a successful payload.
     *
     * @param  mixed  $data  Response payload; `null` when there is nothing to return.
     * @param  string  $message  Human readable summary.
     * @param  int  $status  HTTP status code.
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => [],
        ], $status);
    }

    /**
     * Wrap a failure.
     *
     * @param  ApiErrorCode  $code  Stable error code; also decides the HTTP status.
     * @param  list<array{code: string, field: string|null, message: string}>  $errors  Field level detail; falls back to a single code entry.
     * @param  string|null  $message  Envelope summary; defaults to the code message.
     * @param  array<string, string|list<string>>  $headers  Extra response headers such as `Retry-After`.
     * @param  int|null  $status  Status override when the HTTP layer already decided it.
     */
    public static function error(
        ApiErrorCode $code,
        array $errors = [],
        ?string $message = null,
        array $headers = [],
        ?int $status = null,
    ): JsonResponse {
        $message ??= $code->message();

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors === [] ? [self::errorEntry($code, null)] : $errors,
        ], $status ?? $code->status(), $headers);
    }

    /**
     * Build one envelope error entry.
     *
     * @return array{code: string, field: string|null, message: string}
     */
    public static function errorEntry(ApiErrorCode $code, ?string $field, ?string $message = null): array
    {
        return [
            'code' => $code->value,
            'field' => $field,
            'message' => $message ?? $code->message(),
        ];
    }

    /**
     * Flatten Laravel validation messages into envelope error entries.
     *
     * @return list<array{code: string, field: string|null, message: string}>
     */
    public static function validationErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = self::errorEntry(ApiErrorCode::ValidationFailed, $field, $message);
            }
        }

        return $errors;
    }
}
