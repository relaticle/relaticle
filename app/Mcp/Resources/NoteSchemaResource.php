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

#[Description('Schema for notes including available custom fields. Read this before creating or updating notes.')]
#[Uri('relaticle://schema/note')]
#[MimeType('application/json')]
final class NoteSchemaResource extends Resource
{
    use ResolvesEntitySchema;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $schema = [
            'entity' => 'note',
            'description' => 'Free-form notes attached to CRM records.',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true],
            ],
            'custom_fields' => $this->resolveCustomFields($user, 'note'),
            'filterable_fields' => $this->resolveFilterableFields($user, 'note'),
            'relationships' => ['companies', 'people', 'opportunities'],
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

        return Response::text(json_encode($schema, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
