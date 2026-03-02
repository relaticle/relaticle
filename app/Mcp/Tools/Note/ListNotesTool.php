<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\ListNotes;
use App\Http\Resources\V1\NoteResource;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List notes in the CRM with optional search and pagination.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListNotesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Search notes by title.'),
            'per_page' => $schema->integer()->description('Results per page (default 15, max 100).')->default(15),
            'page' => $schema->integer()->description('Page number.')->default(1),
        ];
    }

    public function handle(Request $request, ListNotes $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $query = [];

        if ($search = $request->get('search')) {
            $query['filter']['title'] = $search;
        }

        if ($page = $request->get('page')) {
            $query['page'] = $page;
        }

        if ($perPage = $request->get('per_page')) {
            $query['per_page'] = $perPage;
        }

        request()->merge($query);

        $notes = $action->execute($user, (int) $request->get('per_page', 15));

        return Response::text(
            NoteResource::collection($notes)->toJson(JSON_PRETTY_PRINT)
        );
    }
}
