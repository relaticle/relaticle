<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Note\CreateNote;
use App\Actions\Note\DeleteNote;
use App\Actions\Note\ListNotes;
use App\Actions\Note\UpdateNote;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\StoreNoteRequest;
use App\Http\Requests\Api\V1\UpdateNoteRequest;
use App\Http\Resources\V1\NoteResource;
use App\Models\Note;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group Notes
 *
 * Manage notes in your CRM workspace.
 */
final readonly class NotesController
{
    public function index(Request $request, ListNotes $action): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return NoteResource::collection($action->execute($user));
    }

    #[ResponseFromApiResource(NoteResource::class, Note::class, status: 201)]
    public function store(StoreNoteRequest $request, CreateNote $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $note = $action->execute($user, $request->validated(), CreationSource::API);

        return new NoteResource($note->load('customFieldValues.customField'))
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(NoteResource::class, Note::class)]
    public function show(Note $note): NoteResource
    {
        Gate::authorize('view', $note);

        $note->loadMissing('customFieldValues.customField');

        return new NoteResource($note);
    }

    #[ResponseFromApiResource(NoteResource::class, Note::class)]
    public function update(UpdateNoteRequest $request, Note $note, UpdateNote $action): NoteResource
    {
        /** @var User $user */
        $user = $request->user();

        $note = $action->execute($user, $note, $request->validated());

        return new NoteResource($note->load('customFieldValues.customField'));
    }

    #[Response(status: 204)]
    public function destroy(Request $request, Note $note, DeleteNote $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, $note);

        return response()->json(null, 204);
    }
}
