<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Resources\Concerns\ResolvesEntitySchema;
use App\Mcp\Resources\Contracts\ProvidesEntitySchema;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Schema for people (contacts) including available custom fields. Read this before creating or updating people.')]
#[Uri('relaticle://schema/people')]
#[MimeType('application/json')]
final class PeopleSchemaResource extends Resource implements ProvidesEntitySchema
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

        return Response::text(json_encode($this->toSchema($user), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toSchema(User $user): array
    {
        return [
            'entity' => 'people',
            'description' => 'Individual contacts associated with companies.',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true],
                'company_id' => ['type' => 'string', 'required' => false, 'description' => 'Links to a company'],
            ],
            'custom_fields' => $this->resolveCustomFields($user, 'people'),
            'filterable_fields' => $this->resolveFilterableFields($user, 'people'),
            'relationships' => ['creator', 'company', 'tasks', 'notes'],
            'aggregate_includes' => [
                'tasksCount' => 'Count of related tasks',
                'notesCount' => 'Count of related notes',
            ],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys. Use "filter" param in list tools to filter by custom field values with operators.',
        ];
    }
}
