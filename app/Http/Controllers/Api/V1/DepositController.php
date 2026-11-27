<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\RecordApiRequest;
use App\Http\Requests\DepositRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves deposit endpoints.
 */
final class DepositController extends Controller
{
    public function __construct(private readonly DepositService $deposits) {}

    /**
     * Credit an amount to the authenticated member's account.
     */
    #[OA\Post(
        path: '/transactions/deposit',
        operationId: 'deposit',
        tags: ['Transactions'],
        summary: 'Deposit funds into the authenticated account',
        description: 'Requires a bearer token and a unique Idempotency-Key header. Replays the stored response for a repeated key with the same payload, and returns 409 for a different payload or while the original request is still processing.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'Idempotency-Key',
                in: 'header',
                required: true,
                description: 'Client generated key, at most '.EnsureIdempotentRequest::MAX_KEY_LENGTH.' characters, unique per user and operation.',
                schema: new OA\Schema(type: 'string', maxLength: EnsureIdempotentRequest::MAX_KEY_LENGTH),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount'],
                properties: [
                    new OA\Property(property: 'amount', type: 'integer', minimum: DepositService::MIN_AMOUNT, maximum: DepositService::MAX_AMOUNT, example: 100000),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Deposit accepted. Balance and ledger entry returned.',
                content: new OA\JsonContent(
                    required: ['success', 'message', 'data', 'errors'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Deposit successful.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'balance', type: 'integer', example: 150000),
                                new OA\Property(
                                    property: 'transaction',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'transaction_id', type: 'string', example: '271126-a1b2-c3d4e5'),
                                        new OA\Property(property: 'type', type: 'string', example: 'deposit'),
                                        new OA\Property(property: 'amount', type: 'integer', example: 100000),
                                        new OA\Property(property: 'balance_before', type: 'integer', example: 50000),
                                        new OA\Property(property: 'balance_after', type: 'integer', example: 150000),
                                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                    ],
                                ),
                            ],
                        ),
                        new OA\Property(
                            property: 'errors',
                            type: 'array',
                            items: new OA\Items(type: 'object'),
                            example: [],
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Bearer token missing, invalid, or expired.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'),
            ),
            new OA\Response(
                response: 404,
                description: 'The authenticated user has no account.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'),
            ),
            new OA\Response(
                response: 409,
                description: 'The idempotency key is already in use with a different payload or is still processing.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'),
            ),
            new OA\Response(
                response: 422,
                description: 'Payload validation failed or the Idempotency-Key header is missing or too long.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'),
            ),
        ],
    )]
    public function store(DepositRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->deposits->deposit(
            $user,
            $request->integer('amount'),
            (string) $request->attributes->get(RecordApiRequest::TRACE_ID_ATTRIBUTE),
        );

        return ApiResponse::success($result, 'Deposit successful.', Response::HTTP_CREATED);
    }
}
