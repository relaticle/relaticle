<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Resources\Concerns\ResolvesEntitySchema;
use App\Models\PersonalAccessToken;
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

    public function shouldRegister(): bool
    {
        $token = auth()->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return true;
        }
        if (! $token->getKey()) {
            return true;
        }

        return $token->can('read');
    }

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
            'filterable_fields' => $this->resolveFilterableFields($user, 'company'),
            'relationships' => ['creator', 'accountOwner', 'people', 'opportunities'],
            'aggregate_includes' => [
                'peopleCount' => 'Count of related people',
                'opportunitiesCount' => 'Count of related opportunities',
                'tasksCount' => 'Count of related tasks',
                'notesCount' => 'Count of related notes',
            ],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys. Use "filter" param in list tools to filter by custom field values with operators (eq, gt, gte, lt, lte, contains, in, has_any). Example: {"name": "Acme", "custom_fields": {"icp": true}}',
        ];

        return Response::text(json_encode($schema, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
