<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RecordApiRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Serves credential-based authentication endpoints.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Authenticates the user and binds them to the request for logging.
     */
    #[OA\Post(
        path: '/auth/login',
        operationId: 'login',
        tags: ['Authentication'],
        summary: 'Exchange credentials for a bearer token',
        description: 'Public endpoint. Returns a short-lived HS256 token and allows 5 attempts per minute per IP.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'member@example.test'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Credentials accepted. Token issued.',
                content: new OA\JsonContent(
                    required: ['success', 'message', 'data', 'errors'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Authenticated.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'access_token', type: 'string'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                                new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
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
                description: 'Email or password is incorrect.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'),
            ),
            new OA\Response(
                response: 422,
                description: 'Payload validation failed.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'),
            ),
            new OA\Response(
                response: 429,
                description: 'Too many login attempts from this IP.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'),
            ),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $result = $this->auth->login(
            $credentials['email'],
            $credentials['password'],
            (string) $request->attributes->get(RecordApiRequest::TRACE_ID_ATTRIBUTE),
        );

        // A form request is a copy of the HTTP request, so bind the actor on the
        // original that the request logging middleware finalizes.
        request()->setUserResolver(fn () => $result['user']);

        return ApiResponse::success($result['token'], 'Authenticated.');
    }
}
