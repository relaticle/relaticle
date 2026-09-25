<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Enums\CrmEntity;
use App\Mcp\Resources\Contracts\ProvidesEntitySchema;
use App\Mcp\Schema\CustomFieldSchema;
use App\Mcp\Schema\McpSchemaCache;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Enums\Role;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Annotations\Audience;
use Laravel\Mcp\Server\Annotations\Priority;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Schema for companies including available custom fields. Read this before creating or updating companies.')]
#[Uri('relaticle://schema/company')]
#[MimeType('application/json')]
#[Cacheable(ttlMs: McpSchemaCache::TTL * 1000, scope: CacheScope::Private)]
#[Audience(Role::Assistant)]
#[Priority(0.8)]
final class CompanySchemaResource extends Resource implements ProvidesEntitySchema
{
    public function __construct(private readonly CustomFieldSchema $schema) {}

    private function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

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

        return Response::text(json_encode($this->toSchema($user), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toSchema(User $user): array
    {
        return [
            'entity' => $this->entity()->value,
            'description' => 'Organizations and businesses tracked in the CRM.',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true],
                'account_owner_id' => ['type' => 'string', 'required' => false, 'description' => 'Workspace member ID from whoami.'],
            ],
            'custom_fields' => $this->schema->fields($user, $this->entity()),
            'filterable_fields' => $this->schema->filterableFields($user, $this->entity()),
            'relationships' => ['creator', 'accountOwner', 'people', 'opportunities', 'tasks', 'notes'],
            'aggregate_includes' => [
                'peopleCount' => 'Count of related people',
                'opportunitiesCount' => 'Count of related opportunities',
                'tasksCount' => 'Count of related tasks',
                'notesCount' => 'Count of related notes',
            ],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys. Use "filter" param in list tools to filter by custom field values with operators (eq, gt, gte, lt, lte, contains, in, has_any). Example: {"name": "Acme", "custom_fields": {"icp": true}}.',
        ];
    }
}
