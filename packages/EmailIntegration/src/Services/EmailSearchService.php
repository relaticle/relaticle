<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class EmailSearchService
{
    public const int MIN_TEXT_LENGTH = 2;

    public const int MAX_LENGTH = 200;

    private const int MIN_INDEXED_LENGTH = 3;

    public static function clean(string $search): string
    {
        $search = trim(str_replace("\0", '', mb_scrub($search, 'UTF-8')));

        return trim(mb_substr($search, 0, self::MAX_LENGTH));
    }

    /**
     * Match only fields the viewer is allowed to see. Subject and body stay out of
     * metadata-only rows. Participant search skips other people's BCC rows.
     *
     * The ids each index matches lead, so the access gates only test rows that already match.
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

        // Under three characters no trigram applies, so no index can lead and every row is tested.
        if (mb_strlen($search) < self::MIN_INDEXED_LENGTH) {
            return $query->where(fn (Builder $outer): Builder => $this->orWhereTextVisible(
                $outer->whereHas('participants', fn (Builder $participants): Builder => $participants->matchingSearch($viewer, $search)),
                $viewer,
                $textQuery,
            ));
        }

        if ($textQuery === null) {
            return $query->whereIn('emails.id', $this->participantMatches($viewer, $search));
        }

        return $query
            ->whereIn('emails.id', $this->matchingIds($viewer, $this->participantMatches($viewer, $search), $textQuery))
            ->where(fn (Builder $outer): Builder => $this->orWhereTextVisible($outer, $viewer, $textQuery)
                ->orWhereHas('participants', fn (Builder $participants): Builder => $participants->matchingSearch($viewer, $search)));
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
     * One scan of the workspace's emails whose arms each have an index, so the planner can
     * combine them and estimate how many ids match. A union would hide that estimate.
     *
     * @param  array{0: literal-string, 1: list<string>}  $textQuery
     */
    private function matchingIds(User $viewer, BaseBuilder $participants, array $textQuery): BaseBuilder
    {
        [$sql, $bindings] = $textQuery;

        return DB::table('emails as search_matches')
            ->where('search_matches.workspace_id', $viewer->current_workspace_id)
            ->where(fn (BaseBuilder $match): BaseBuilder => $match
                ->whereRaw("search_matches.subject_search @@ ({$sql})", $bindings)
                ->orWhereRaw("search_matches.body_search @@ ({$sql})", $bindings)
                ->orWhere('search_matches.id', '=', $this->anyOf($participants))
                ->addBinding($participants->getBindings(), 'where'))
            ->select('search_matches.id');
    }

    // `= any(array(...))` lets the primary key join the bitmap; `in (...)` inside an OR cannot.
    private function anyOf(BaseBuilder $subquery): Expression
    {
        return new readonly class($subquery->toSql()) implements Expression
        {
            public function __construct(private string $sql) {}

            public function getValue(Grammar $grammar): string
            {
                return "any(array({$this->sql}))";
            }
        };
    }

    private function participantMatches(User $viewer, string $search): BaseBuilder
    {
        return EmailParticipant::query()
            ->matchingSearch($viewer, $search)
            ->whereHas('email', fn (Builder $email): Builder => $email->where('emails.workspace_id', $viewer->current_workspace_id))
            ->select('email_participants.email_id')
            ->toBase();
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
                    ->orWhereExists(fn (BaseBuilder $copyQuery): BaseBuilder => $this->syncedCopyExists($copyQuery, $viewerId))
                    ->orWhereHas('shares', fn (Builder $shareQuery): Builder => $shareQuery
                        ->where('shared_with', $viewerId)
                        ->whereIn('tier', $tiers))
                    ->orWhere(function (Builder $crossShare) use ($viewerId, $tiers): void {
                        $crossShare
                            ->whereDoesntHave('shares', fn (Builder $shareQuery): Builder => $shareQuery
                                ->where('shared_with', $viewerId))
                            ->whereExists(fn (BaseBuilder $shareQuery): BaseBuilder => $this->crossMessageShareExists(
                                $shareQuery,
                                $viewerId,
                                $tiers,
                            ));
                    })
                    ->orWhere(function (Builder $byDefault) use ($viewerId, $workspaceId, $tiers): void {
                        $byDefault
                            ->where(fn (Builder $publicGate): Builder => VisibleEmailScope::wherePublicToTeammates($publicGate, $workspaceId))
                            ->whereIn('emails.privacy_tier', $tiers)
                            ->whereExists(fn (BaseBuilder $link): BaseBuilder => $link
                                ->from('emailables')
                                ->whereColumn('emailables.email_id', 'emails.id'))
                            ->whereDoesntHave('shares', fn (Builder $shareQuery): Builder => $shareQuery
                                ->where('shared_with', $viewerId))
                            ->whereNotExists(fn (BaseBuilder $shareQuery): BaseBuilder => $this->crossMessageShareExists(
                                $shareQuery,
                                $viewerId,
                            ));
                    });
            });
    }

    private function syncedCopyExists(BaseBuilder $query, string $viewerId): BaseBuilder
    {
        return $query->from('emails as viewer_copies')
            ->join('connected_accounts as viewer_copy_accounts', 'viewer_copy_accounts.id', '=', 'viewer_copies.connected_account_id')
            ->whereNull('viewer_copies.deleted_at')
            ->whereNull('viewer_copy_accounts.deleted_at')
            ->whereColumn('viewer_copies.workspace_id', 'emails.workspace_id')
            ->whereColumn('viewer_copies.rfc_message_id', 'emails.rfc_message_id')
            ->where('viewer_copies.user_id', $viewerId)
            ->whereNotNull('emails.rfc_message_id');
    }

    /**
     * @param  list<string>|null  $tiers
     */
    private function crossMessageShareExists(BaseBuilder $query, string $viewerId, ?array $tiers = null): BaseBuilder
    {
        $query
            ->from('email_shares')
            ->join('emails as share_source_emails', 'share_source_emails.id', '=', 'email_shares.email_id')
            ->where('email_shares.shared_with', $viewerId)
            ->whereColumn('share_source_emails.workspace_id', 'emails.workspace_id')
            ->whereColumn('share_source_emails.rfc_message_id', 'emails.rfc_message_id')
            ->whereNotNull('emails.rfc_message_id');

        if ($tiers !== null) {
            $query->whereIn('email_shares.tier', $tiers);
        }

        return $query;
    }
}
