<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\CrmEntity;
use App\Mcp\Resources\CompanySchemaResource;
use App\Mcp\Resources\NoteSchemaResource;
use App\Mcp\Resources\OpportunitySchemaResource;
use App\Mcp\Resources\PeopleSchemaResource;
use App\Mcp\Resources\TaskSchemaResource;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Get CRM Schema')]
#[Description('Get the current workspace schema for one CRM entity, including active custom fields, choice options, filters, and relationships.')]
final class GetCrmSchemaTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()->description('One of: company, people, opportunity, task, note.')->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()->required(),
            'description' => $schema->string()->required(),
            'fields' => $schema->object()->required(),
            'custom_fields' => $schema->object()->required(),
            'filterable_fields' => $schema->object()->required(),
            'relationships' => $schema->array()->items($schema->string())->required(),
            'writable_relationships' => $schema->object(),
            'tools_hint' => $schema->string(),
            'aggregate_includes' => $schema->object(),
            'usage' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(CrmEntity::morphAliases())],
        ]);

        $resource = match (CrmEntity::from((string) $validated['entity_type'])) {
            CrmEntity::Company => resolve(CompanySchemaResource::class),
            CrmEntity::People => resolve(PeopleSchemaResource::class),
            CrmEntity::Opportunity => resolve(OpportunitySchemaResource::class),
            CrmEntity::Task => resolve(TaskSchemaResource::class),
            CrmEntity::Note => resolve(NoteSchemaResource::class),
        };

        /** @var User $user */
        $user = $request->user();

        return Response::structured($resource->toSchema($user));
    }
}
