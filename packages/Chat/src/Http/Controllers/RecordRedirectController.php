<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Relaticle\Chat\Support\RecordReferenceResolver;

/**
 * The permanent adapter behind `/r/{type}/{id}` links cited in chat transcripts.
 * The path shape is frozen public surface: it never changes and never moves
 * hosts. A renamed type slug gets a permanent alias added to $types below,
 * and a new reference kind gets a new type segment here rather than a new
 * scheme.
 *
 * `RecordReferenceResolver::urlFor()` builds the panel URL for these types with
 * no database query, so on its own it would happily return a URL for a record
 * belonging to another team or one that does not exist at all. The ownership
 * check therefore lives here, before the redirect: the record is fetched and
 * team membership is required. Both the missing and the foreign-team case
 * 404 identically, so the response can never be used to probe whether a
 * record exists in a tenant the caller does not belong to.
 */
final readonly class RecordRedirectController
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const array TYPES = [
        'company' => Company::class,
        'people' => People::class,
        'opportunity' => Opportunity::class,
        'task' => Task::class,
        'note' => Note::class,
    ];

    public function __invoke(Request $request, RecordReferenceResolver $resolver, string $type, string $id): RedirectResponse
    {
        $modelClass = self::TYPES[$type] ?? null;

        abort_if($modelClass === null, 404);

        /** @var User $user */
        $user = $request->user();

        $record = $modelClass::query()->find($id);

        abort_if($record === null, 404);

        abort_unless($user->belongsToTeamId($record->team_id), 404);

        $url = $resolver->urlFor($type, $id);

        abort_if($url === null, 404);

        return redirect($url);
    }
}
