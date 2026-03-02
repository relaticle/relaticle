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

#[Description('Schema for tasks including available custom fields. Read this before creating or updating tasks.')]
#[Uri('relaticle://schema/task')]
#[MimeType('application/json')]
final class TaskSchemaResource extends Resource
{
    use ResolvesEntitySchema;

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
            'relationships' => ['assignees', 'companies', 'people', 'opportunities'],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys.',
        ];

        return Response::text(json_encode($schema, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
