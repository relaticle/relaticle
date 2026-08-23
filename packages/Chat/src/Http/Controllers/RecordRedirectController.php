<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Relaticle\Chat\Support\RecordReferenceResolver;

/**
 * The permanent adapter behind `/r/{type}/{id}` links cited in chat transcripts.
 * The path shape is frozen public surface: it never changes and never moves
 * hosts. A renamed type slug gets a permanent alias mapped back to its model
 * here, and a new reference kind gets a new type segment rather than a new
 * scheme.
 *
 * `RecordReferenceResolver::urlFor()` builds panel URLs for the five CRM types
 * in CHIP_TYPES with no database query, so on its own it would happily return a URL
 * for a record belonging to another team or one that does not exist at all.
 * The ownership check for those five types therefore lives here, before the
 * redirect: the record is fetched and team membership is required. Both the
 * missing and the foreign-team case 404 identically, so the response can never
 * be used to probe whether a record exists in a tenant the caller does not
 * belong to. The URL is then built against the record's OWN team, not the
 * caller's current team, so a citation to a record in a non-current (but
 * still accessible) team lands on that team's panel.
 *
 * A record can also be soft-deleted after a chat transcript cited it: the
 * lookup is `withTrashed()` and, for a team member, a trashed record renders
 * a friendly "this record no longer exists" page (410, the state it describes)
 * instead of redirecting. The
 * team-membership check still runs BEFORE the trashed check, so a foreign
 * caller 404s whether the record is live, trashed, or absent. Trashed state
 * is only ever revealed to someone who could already see the record.
 *
 * `custom_field` is not in CHIP_TYPES and skips this fetch-then-check step: custom
 * fields have no `team_id` column (they are tenant-scoped by `tenant_id`), and
 * `RecordReferenceResolver::customFieldUrl()` already runs its own
 * tenant-scoped query and returns null for a foreign or missing field, so it
 * is safe to resolve directly against the caller's current team.
 */
final readonly class RecordRedirectController
{
    private const string CUSTOM_FIELD_TYPE = 'custom_field';

    public function __invoke(Request $request, RecordReferenceResolver $resolver, string $type, string $id): RedirectResponse|Response
    {
        if ($type === self::CUSTOM_FIELD_TYPE) {
            $url = $resolver->urlFor($type, $id);

            abort_if($url === null, 404);

            return redirect($url);
        }

        /** @var class-string<Company|People|Opportunity|Task|Note>|null $modelClass */
        $modelClass = in_array($type, RecordReferenceResolver::CHIP_TYPES, true)
            ? Relation::getMorphedModel($type)
            : null;

        abort_if($modelClass === null, 404);

        /** @var User $user */
        $user = $request->user();

        $record = $modelClass::query()->withTrashed()->find($id);

        abort_if($record === null, 404);

        abort_unless($user->belongsToTeamId($record->team_id), 404);

        if ($record->trashed()) {
            return response()->view('chat::record-gone', status: Response::HTTP_GONE);
        }

        $url = $resolver->urlFor($type, $id, $record->team);

        abort_if($url === null, 404);

        return redirect($url);
    }
}
