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

#[Description('Schema for notes including available custom fields. Read this before creating or updating notes.')]
#[Uri('relaticle://schema/note')]
#[MimeType('application/json')]
#[Cacheable(ttlMs: McpSchemaCache::TTL * 1000, scope: CacheScope::Private)]
#[Audience(Role::Assistant)]
#[Priority(0.8)]
final class NoteSchemaResource extends Resource implements ProvidesEntitySchema
{
    public function __construct(private readonly CustomFieldSchema $schema) {}

    private function entity(): CrmEntity
    {
        return CrmEntity::Note;
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
            'description' => 'Free-form notes attached to CRM records.',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true],
            ],
            'custom_fields' => $this->schema->fields($user, $this->entity()),
            'filterable_fields' => $this->schema->filterableFields($user, $this->entity()),
            'relationships' => ['creator', 'companies', 'people', 'opportunities'],
            'writable_relationships' => [
                'company_ids' => [
                    'type' => 'array of string IDs',
                    'description' => 'Link note to companies on create/update. Omit to leave unchanged, pass [] to remove all.',
                ],
                'people_ids' => [
                    'type' => 'array of string IDs',
                    'description' => 'Link note to people on create/update. Omit to leave unchanged, pass [] to remove all.',
                ],
                'opportunity_ids' => [
                    'type' => 'array of string IDs',
                    'description' => 'Link note to opportunities on create/update. Omit to leave unchanged, pass [] to remove all.',
                ],
            ],
            'tools_hint' => 'Use attach-note-to-entities and detach-note-from-entities tools for post-creation relationship management.',
            'aggregate_includes' => [
                'companiesCount' => 'Count of related companies',
                'peopleCount' => 'Count of related people',
                'opportunitiesCount' => 'Count of related opportunities',
            ],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys.',
        ];
    }
}
