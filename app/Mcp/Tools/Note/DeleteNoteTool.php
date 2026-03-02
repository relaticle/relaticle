<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\DeleteNote;
use App\Models\Note;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete a note from the CRM (soft delete).')]
#[IsDestructive]
final class DeleteNoteTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The note ID to delete.')->required(),
        ];
    }

    public function handle(Request $request, DeleteNote $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'id' => ['required', 'string'],
        ]);

        /** @var Note $note */
        $note = Note::query()->findOrFail($validated['id']);
        Gate::authorize('delete', $note);

        $action->execute($user, $note);

        return Response::text("Note '{$note->title}' has been deleted.");
    }
}
