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

#[Description('Schema for people (contacts) including available custom fields. Read this before creating or updating people.')]
#[Uri('relaticle://schema/people')]
#[MimeType('application/json')]
final class PeopleSchemaResource extends Resource
{
    use ResolvesEntitySchema;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $schema = [
            'entity' => 'people',
            'description' => 'Individual contacts associated with companies.',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true],
                'company_id' => ['type' => 'string', 'required' => false, 'description' => 'Links to a company'],
            ],
            'custom_fields' => $this->resolveCustomFields($user, 'people'),
            'relationships' => ['company', 'opportunities', 'tasks', 'notes'],
            'usage' => 'Pass custom field values in the "custom_fields" object using field codes as keys.',
        ];

        return Response::text(json_encode($schema, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
