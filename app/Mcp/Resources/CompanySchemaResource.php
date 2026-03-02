<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Resources\Concerns\ResolvesEntitySchema;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Schema for companies including available custom fields. Read this before creating or updating companies.')]
#[Uri('relaticle://schema/company')]
#[MimeType('application/json')]
final class CompanySchemaResource extends Resource
{
    use ResolvesEntitySchema;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $schema = [
            'entity' => 'company',
            'description' => 'Organizations and businesses tracked in the CRM.',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'custom_fields' => $this->resolveCustomFields($user, 'company'),
            'relationships' => ['people', 'opportunities', 'tasks', 'notes'],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys. Example: {"name": "Acme", "custom_fields": {"icp": true, "domains": ["https://acme.com"]}}',
        ];

        return Response::text(json_encode($schema, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
