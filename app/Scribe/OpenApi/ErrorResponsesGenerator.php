<?php

declare(strict_types=1);

namespace App\Scribe\OpenApi;

use App\Http\Middleware\EnsureTokenHasAbility;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;

final class ErrorResponsesGenerator extends OpenApiGenerator
{
    private const string ERROR_SCHEMA = '#/components/schemas/Error';

    private const string VALIDATION_ERROR_SCHEMA = '#/components/schemas/ValidationError';

    /**
     * @param  array<string, mixed>  $root
     * @param  array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}>  $groupedEndpoints
     * @return array<string, mixed>
     */
    public function root(array $root, array $groupedEndpoints): array
    {
        $root['components']['schemas']['Error'] = [
            'type' => 'object',
            'required' => ['message'],
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Human-readable explanation of the failure.'],
            ],
        ];

        $root['components']['schemas']['ValidationError'] = [
            'type' => 'object',
            'required' => ['message', 'errors'],
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Summary of the first validation failure.'],
                'errors' => [
                    'type' => 'object',
                    'description' => 'Validation messages keyed by the offending field name.',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ];

        $root['components']['securitySchemes']['default']['description'] = implode("\n\n", [
            'Generate an access token from **Settings > Access Tokens** in the Relaticle app.',
            'Tokens carry scoped abilities: `read` (GET), `create` (POST), `update` (PUT/PATCH), `delete` (DELETE). A request whose token lacks the ability for its HTTP method is rejected with 403.',
            'Every token is bound to one workspace; responses only ever contain that workspace\'s records.',
        ]);

        return $root;
    }

    /**
     * @param  array<string, mixed>  $pathItem
     * @param  array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}>  $groupedEndpoints
     * @return array<string, mixed>
     */
    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $method = strtoupper($endpoint->httpMethods[0]);
        $ability = EnsureTokenHasAbility::abilityFor($method);

        $errors = [
            '401' => $this->errorResponse('Missing or invalid access token.'),
            '402' => $this->errorResponse('Relaticle Cloud only: the workspace is paused until it has an active subscription. The body carries an `upgrade_url`.'),
            '403' => $this->errorResponse("The token lacks the `{$ability}` ability, or its user no longer belongs to the token's workspace."),
            '429' => [
                ...$this->errorResponse('Rate limit exceeded. Back off for the number of seconds in `Retry-After`.'),
                'headers' => [
                    'Retry-After' => ['schema' => ['type' => 'integer'], 'description' => 'Seconds until the limit resets.'],
                    'X-RateLimit-Limit' => ['schema' => ['type' => 'integer']],
                    'X-RateLimit-Remaining' => ['schema' => ['type' => 'integer']],
                ],
            ],
        ];

        if ($endpoint->urlParameters !== []) {
            $errors['404'] = $this->errorResponse('No record with that ID exists in the workspace.');
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $errors['422'] = $this->errorResponse('Validation failed.', self::VALIDATION_ERROR_SCHEMA);
        }

        $existing = is_array($pathItem['responses'] ?? null) ? $pathItem['responses'] : [];

        $pathItem['responses'] = $existing + $errors;
        ksort($pathItem['responses']);

        return $pathItem;
    }

    /** @return array<string, mixed> */
    private function errorResponse(string $description, string $schema = self::ERROR_SCHEMA): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => $schema]]],
        ];
    }
}
