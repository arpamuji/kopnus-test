<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'KOPNUS API',
    description: 'Member financial transaction API',
)]
#[OA\Server(url: '/api/v1', description: 'Versioned API root')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'HS256 bearer token issued by the login endpoint.',
)]
#[OA\Schema(
    schema: 'ApiErrorEntry',
    description: 'One error entry inside the response envelope.',
    required: ['code', 'field', 'message'],
    properties: [
        new OA\Property(property: 'code', type: 'string', example: 'INVALID_CREDENTIALS'),
        new OA\Property(property: 'field', type: 'string', nullable: true, example: 'email'),
        new OA\Property(property: 'message', type: 'string', example: 'Email or password is incorrect.'),
    ],
)]
#[OA\Schema(
    schema: 'ApiErrorEnvelope',
    description: 'Envelope returned by every API error response.',
    required: ['success', 'message', 'data', 'errors'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'Email or password is incorrect.'),
        new OA\Property(property: 'data', type: 'object', nullable: true),
        new OA\Property(
            property: 'errors',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/ApiErrorEntry'),
        ),
    ],
)]
/**
 * Base controller that hosts the shared OpenAPI schemas.
 */
abstract class Controller {}
