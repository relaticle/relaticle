<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class EmailSearchService
{
    public const int MIN_TEXT_LENGTH = 2;

    public const int MAX_LENGTH = 200;

    private const int MIN_INDEXED_LENGTH = 3;

    private const int MAX_LISTED_MATCHES = 10_000;

    public static function clean(string $search): string
    {
        $search = trim(str_replace("\0", '', mb_scrub($search, 'UTF-8')));

        return trim(mb_substr($search, 0, self::MAX_LENGTH));
    }

    /**
     * Match only fields the viewer is allowed to see. Subject and body stay out of
     * metadata-only rows. Participant search skips other people's BCC rows.
     *
     * The matches are listed first from the indexes, so the access gates only test rows that match.
     *
     * @param  Builder<Email>|Relation<Email, *, *>  $query
     * @return Builder<Email>|Relation<Email, *, *>
     */
    public function applyToQuery(Builder|Relation $query, User $viewer, string $search): Builder|Relation
    {
        $search = self::clean($search);

        if ($search === '') {
            return $query;
        }

        $textQuery = $this->textQuery($search);
        $ids = mb_strlen($search) < self::MIN_INDEXED_LENGTH ? null : $this->matchingIds($viewer, $search, $textQuery);

        // Without a short list of matches, no index can lead and the view indexes stop early instead.
        if ($ids === null) {
            return $query->where(fn (Builder $outer): Builder => $this->orWhereTextVisible(
                $outer->whereHas('participants', fn (Builder $participants): Builder => $participants->matchingSearch($viewer, $search)),
                $viewer,
                $textQuery,
            ));
        }

        if ($ids === []) {
            return $query->whereRaw('false');
        }

        // A set the planner can hash: `= any(array)` compares every id on each row it filters.
        return $query->whereRaw('emails.id in (select unnest(?::bpchar[]))', [$this->idArray($ids)]);
    }

    // True when the matches are a short list of ids, so a caller may read them in any order.
    public function listsMatches(User $viewer, string $search): bool
    {
        $search = self::clean($search);

        return mb_strlen($search) >= self::MIN_INDEXED_LENGTH
            && $this->matchingIds($viewer, $search, $this->textQuery($search)) !== null;
    }

    /**
     * The visible matches as ids, found from the indexes alone, or null when there are too many
     * to list. Once per viewer and search, because counts() searches each view.
     *
     * @param  array{0: literal-string, 1: list<string>}|null  $textQuery
     * @return list<string>|null
     */
    private function matchingIds(User $viewer, string $search, ?array $textQuery): ?array
    {
        return once(function () use ($viewer, $search, $textQuery): ?array {
            $participantIds = $this->firstMatches(EmailParticipant::query()
                ->join('emails as participant_emails', 'participant_emails.id', '=', 'email_participants.email_id')
                ->where('participant_emails.workspace_id', $viewer->current_workspace_id)
                ->matchingSearch($viewer, $search, 'participant_emails.user_id')
                ->toBase(), 'email_participants.email_id');

            if ($participantIds === null) {
                return null;
            }

            return $this->firstMatches(Email::query()
                ->withoutGlobalScope(ActiveAccountScope::class)
                ->where('emails.workspace_id', $viewer->current_workspace_id)
                ->where(fn (Builder $match): Builder => $this->orWhereTextVisible(
                    $match->whereRaw('emails.id = any(?::bpchar[])', [$this->idArray($participantIds)]),
                    $viewer,
                    $textQuery,
                ))
                ->toBase(), 'emails.id');
        });
    }

    /** @return list<string>|null */
    private function firstMatches(BaseBuilder $query, string $column): ?array
    {
        $matches = array_values($query->limit(self::MAX_LISTED_MATCHES + 1)->pluck($column)
            ->map(fn (mixed $id): string => (string) $id)
            ->all());

        return count($matches) > self::MAX_LISTED_MATCHES ? null : $matches;
    }

    /** @param  list<string>  $ids */
    private function idArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }

    /**
     * @param  Builder<Email>  $query
     * @param  array{0: literal-string, 1: list<string>}|null  $textQuery
     * @return Builder<Email>
     */
    private function orWhereTextVisible(Builder $query, User $viewer, ?array $textQuery): Builder
    {
        if ($textQuery === null) {
            return $query;
        }

        [$sql, $bindings] = $textQuery;

        return $query
            ->orWhere(fn (Builder $subjectQuery): Builder => $this->whereViewerCanSeeSubject(
                $subjectQuery->whereRaw("emails.subject_search @@ ({$sql})", $bindings),
                $viewer,
            ))
            ->orWhere(fn (Builder $bodyQuery): Builder => $this->whereViewerCanSeeBody(
                $bodyQuery->whereRaw("emails.body_search @@ ({$sql})", $bindings),
                $viewer,
            ));
    }

    /**
     * Whole words through websearch syntax, and a trailing bare word as a prefix. Only letters
     * and digits reach to_tsquery, so no user text is parsed as tsquery syntax.
     *
     * @return array{0: literal-string, 1: list<string>}|null
     */
    private function textQuery(string $search): ?array
    {
        if (mb_strlen($search) < self::MIN_TEXT_LENGTH || ! $this->hasPositiveTerm($search)) {
            return null;
        }

        if (preg_match('/(?:^|\s)([\p{L}\p{N}]{2,})$/u', $search, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return ["websearch_to_tsquery('simple', ?)", [$search]];
        }

        $prefix = ["to_tsquery('simple', ?)", [$match[1][0].':*']];
        $rest = substr($search, 0, $match[1][1]);

        if (preg_match('/[\p{L}\p{N}]/u', $rest) !== 1) {
            return $prefix;
        }

        return ["websearch_to_tsquery('simple', ?) && {$prefix[0]}", [$rest, ...$prefix[1]]];
    }

    // A query of only negated terms matches nearly every row, so it never reaches the text columns.
    private function hasPositiveTerm(string $search): bool
    {
        $positive = preg_replace('/(?:^|\s)-+\s*(?:"[^"]*"?|\S*)/u', ' ', $search) ?? '';

        return preg_match('/[\p{L}\p{N}]/u', $positive) === 1;
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    public function whereSubjectVisibleTo(Builder $query, User $viewer): Builder
    {
        return $this->whereViewerCanSeeSubject($query, $viewer);
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function whereViewerCanSeeSubject(Builder $query, User $viewer): Builder
    {
        return $this->whereViewerHasAccessAtTiers($query, $viewer, [
            EmailPrivacyTier::SUBJECT->value,
            EmailPrivacyTier::FULL->value,
        ]);
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function whereViewerCanSeeBody(Builder $query, User $viewer): Builder
    {
        return $this->whereViewerHasAccessAtTiers($query, $viewer, [
            EmailPrivacyTier::FULL->value,
        ]);
    }

    /**
     * Match PrivacyService: a per-viewer share overrides the email default.
     *
     * @param  Builder<Email>  $query
     * @param  list<string>  $tiers
     * @return Builder<Email>
     */
    private function whereViewerHasAccessAtTiers(Builder $query, User $viewer, array $tiers): Builder
    {
        $viewerId = $viewer->getKey();
        $workspaceId = $viewer->current_workspace_id;

        return $query
            ->where(fn (Builder $unblocked): Builder => VisibleEmailScope::whereNotBlocked($unblocked, $workspaceId))
            ->where(function (Builder $access) use ($viewerId, $workspaceId, $tiers): void {
                $access
                    ->where('emails.user_id', $viewerId)
                    ->orWhereHas('shares', fn (Builder $shareQuery): Builder => $shareQuery
                        ->where('shared_with', $viewerId)
                        ->whereIn('tier', $tiers))
                    ->orWhere(function (Builder $byDefault) use ($viewerId, $workspaceId, $tiers): void {
                        $byDefault
                            ->where(fn (Builder $publicGate): Builder => VisibleEmailScope::wherePublicToTeammates($publicGate, $workspaceId))
                            ->whereIn('emails.privacy_tier', $tiers)
                            ->whereExists(fn (BaseBuilder $link): BaseBuilder => $link
                                ->from('emailables')
                                ->whereColumn('emailables.email_id', 'emails.id'))
                            ->whereDoesntHave('shares', fn (Builder $shareQuery): Builder => $shareQuery
                                ->where('shared_with', $viewerId));
                    });
            });
    }
}
