<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\UpdateNote;
use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\Note;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Update an existing note in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsIdempotent]
final class UpdateNoteTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The note ID to update.')->required(),
            'title' => $schema->string()->description('The note title.'),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, UpdateNote $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $rules = array_merge(
            [
                'id' => ['required', 'string'],
                'title' => ['sometimes', 'string', 'max:255'],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'note', $request->get('custom_fields'), isUpdate: true),
        );

        $validated = $request->validate($rules);

        /** @var Note $note */
        $note = Note::query()->findOrFail($validated['id']);
        Gate::authorize('update', $note);
        unset($validated['id']);

        $note = $action->execute($user, $note, $validated);

        return Response::text(
            new NoteResource($note->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
