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

#[Description('Schema for tasks including available custom fields. Read this before creating or updating tasks.')]
#[Uri('relaticle://schema/task')]
#[MimeType('application/json')]
final class TaskSchemaResource extends Resource
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
            'entity' => 'task',
            'description' => 'Action items and to-dos.',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true],
            ],
            'custom_fields' => $this->resolveCustomFields($user, 'task'),
            'filterable_fields' => $this->resolveFilterableFields($user, 'task'),
            'relationships' => ['creator', 'assignees', 'companies', 'people', 'opportunities'],
            'writable_relationships' => [
                'company_ids' => [
                    'type' => 'array of string IDs',
                    'description' => 'Link task to companies on create/update. Omit to leave unchanged, pass [] to remove all.',
                ],
                'people_ids' => [
                    'type' => 'array of string IDs',
                    'description' => 'Link task to people on create/update. Omit to leave unchanged, pass [] to remove all.',
                ],
                'opportunity_ids' => [
                    'type' => 'array of string IDs',
                    'description' => 'Link task to opportunities on create/update. Omit to leave unchanged, pass [] to remove all.',
                ],
                'assignee_ids' => [
                    'type' => 'array of user IDs',
                    'description' => 'Assign team members to this task. Use whoami tool to discover valid user IDs.',
                ],
            ],
            'tools_hint' => 'Use attach-task-to-entities and detach-task-from-entities tools for post-creation relationship management.',
            'aggregate_includes' => [
                'assigneesCount' => 'Count of assigned users',
                'companiesCount' => 'Count of related companies',
                'peopleCount' => 'Count of related people',
                'opportunitiesCount' => 'Count of related opportunities',
            ],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys. Use "filter" param in list tools to filter by custom field values with operators.',
        ];

        return Response::text(json_encode($schema, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
