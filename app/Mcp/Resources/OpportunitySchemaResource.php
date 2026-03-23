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

#[Description('Schema for opportunities including available custom fields. Read this before creating or updating opportunities.')]
#[Uri('relaticle://schema/opportunity')]
#[MimeType('application/json')]
final class OpportunitySchemaResource extends Resource
{
    use ResolvesEntitySchema;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $schema = [
            'entity' => 'opportunity',
            'description' => 'Sales deals or business opportunities.',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true],
                'company_id' => ['type' => 'string', 'required' => false],
                'contact_id' => ['type' => 'string', 'required' => false, 'description' => 'Links to a person'],
            ],
            'custom_fields' => $this->resolveCustomFields($user, 'opportunity'),
            'filterable_fields' => $this->resolveFilterableFields($user, 'opportunity'),
            'relationships' => ['company', 'contact', 'tasks', 'notes'],
            'aggregate_includes' => [
                'tasksCount' => 'Count of related tasks',
                'notesCount' => 'Count of related notes',
            ],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys. Use "filter" param in list tools to filter by custom field values with operators (eq, gt, gte, lt, lte, contains, in, has_any).',
        ];

        return Response::text(json_encode($schema, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
