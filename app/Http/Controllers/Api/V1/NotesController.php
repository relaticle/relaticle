<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Note\CreateNote;
use App\Actions\Note\DeleteNote;
use App\Actions\Note\ListNotes;
use App\Actions\Note\UpdateNote;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\StoreNoteRequest;
use App\Http\Requests\Api\V1\UpdateNoteRequest;
use App\Http\Resources\V1\NoteResource;
use App\Models\Note;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
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
    #[ResponseFromApiResource(NoteResource::class, Note::class, collection: true, paginate: 15)]
    public function index(IndexRequest $request, ListNotes $action, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        return NoteResource::collection($action->execute(
            user: $user,
            perPage: $request->safe()->integer('per_page', 15),
            useCursor: $request->safe()->has('cursor'),
            request: $request,
        ));
    }

    #[ResponseFromApiResource(NoteResource::class, Note::class, status: 201)]
    public function store(StoreNoteRequest $request, CreateNote $action, #[CurrentUser] User $user): JsonResponse
    {
        $note = $action->execute($user, $request->validated(), CreationSource::API);

        return new NoteResource($note)
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(NoteResource::class, Note::class)]
    public function show(Note $note): NoteResource
    {
        Gate::authorize('view', $note);

        $note->loadMissing('customFieldValues.customField.options');

        return new NoteResource($note);
    }

    #[ResponseFromApiResource(NoteResource::class, Note::class)]
    public function update(UpdateNoteRequest $request, Note $note, UpdateNote $action, #[CurrentUser] User $user): NoteResource
    {
        $note = $action->execute($user, $note, $request->validated());

        return new NoteResource($note);
    }

    #[Response(status: 204)]
    public function destroy(Note $note, DeleteNote $action, #[CurrentUser] User $user): HttpResponse
    {
        $action->execute($user, $note);

        return response()->noContent();
    }
}
