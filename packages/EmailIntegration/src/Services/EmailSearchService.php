<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class EmailSearchService
{
    /**
     * Match only fields the viewer is allowed to see. Subject and body stay out of
     * metadata-only rows. Participant search skips other people's BCC rows.
     *
     * @param  Builder<Email>|Relation<Email, *, *>  $query
     * @return Builder<Email>|Relation<Email, *, *>
     */
    public function applyToQuery(Builder|Relation $query, User $viewer, string $search): Builder|Relation
    {
        $textQuery = $this->textQuery($search);

        return $query->where(function (Builder $outer) use ($search, $textQuery, $viewer): void {
            $outer->whereHas('participants', fn (Builder $participants): Builder => $participants->matchingSearch($viewer, $search));

            if ($textQuery === null) {
                return;
            }

            [$sql, $bindings] = $textQuery;

            $outer->orWhere(fn (Builder $subjectQuery): Builder => $subjectQuery
                ->whereRaw("emails.subject_search @@ ({$sql})", $bindings)
                ->where(fn (Builder $access): Builder => $this->whereViewerCanSeeSubject($access, $viewer)));

            $outer->orWhere(fn (Builder $bodyQuery): Builder => $bodyQuery
                ->whereRaw("emails.body_search @@ ({$sql})", $bindings)
                ->where(fn (Builder $access): Builder => $this->whereViewerCanSeeBody($access, $viewer)));
        });
    }

    /**
     * Whole words through websearch syntax, and a trailing bare word as a prefix. Only letters
     * and digits reach to_tsquery, so no user text is parsed as tsquery syntax.
     *
     * @return array{0: literal-string, 1: list<string>}|null
     */
    private function textQuery(string $search): ?array
    {
        if (preg_match('/[\p{L}\p{N}]/u', $search) !== 1) {
            return null;
        }

        if (preg_match('/(?:^|\s)([\p{L}\p{N}]+)$/u', $search, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return ["websearch_to_tsquery('simple', ?)", [$search]];
        }

        $prefix = ["to_tsquery('simple', ?)", [$match[1][0].':*']];
        $rest = substr($search, 0, $match[1][1]);

        if (preg_match('/[\p{L}\p{N}]/u', $rest) !== 1) {
            return $prefix;
        }

        return ["websearch_to_tsquery('simple', ?) && {$prefix[0]}", [$rest, ...$prefix[1]]];
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
