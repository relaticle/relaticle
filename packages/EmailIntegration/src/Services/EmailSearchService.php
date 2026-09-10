<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;

final readonly class EmailSearchService
{
    /**
     * Match only fields the viewer is allowed to see. Subject and snippet stay
     * out of metadata-only rows; participants remain searchable.
     *
     * @param  Builder<Email>|Relation<Email, *, *>  $query
     * @return Builder<Email>|Relation<Email, *, *>
     */
    public function applyToQuery(Builder|Relation $query, User $viewer, string $search): Builder|Relation
    {
        $needle = '%'.$search.'%';
        $viewerId = $viewer->getKey();

        return $query->where(function (Builder $outer) use ($needle, $viewerId): void {
            $outer->whereHas('participants', function (Builder $participantQuery) use ($needle): void {
                $participantQuery->where(function (Builder $match) use ($needle): void {
                    $match->where('name', 'ilike', $needle)
                        ->orWhere('email_address', 'ilike', $needle);
                });
            });

            $outer->orWhere(function (Builder $subjectQuery) use ($needle, $viewerId): void {
                $subjectQuery
                    ->where('subject', 'ilike', $needle)
                    ->where(fn (Builder $access): Builder => $this->whereViewerCanSeeSubject($access, $viewerId));
            });

            $outer->orWhere(function (Builder $snippetQuery) use ($needle, $viewerId): void {
                $snippetQuery
                    ->where('snippet', 'ilike', $needle)
                    ->where(fn (Builder $access): Builder => $this->whereViewerCanSeeBody($access, $viewerId));
            });
        });
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function whereViewerCanSeeSubject(Builder $query, string $viewerId): Builder
    {
        return $this->whereViewerHasAccessAtTiers($query, $viewerId, [
            EmailPrivacyTier::SUBJECT->value,
            EmailPrivacyTier::FULL->value,
        ]);
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function whereViewerCanSeeBody(Builder $query, string $viewerId): Builder
    {
        return $this->whereViewerHasAccessAtTiers($query, $viewerId, [
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
    private function whereViewerHasAccessAtTiers(Builder $query, string $viewerId, array $tiers): Builder
    {
        return $query->where(function (Builder $access) use ($viewerId, $tiers): void {
            $access
                ->where('user_id', $viewerId)
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
                ->orWhere(function (Builder $byDefault) use ($viewerId, $tiers): void {
                    $byDefault
                        ->whereIn('privacy_tier', $tiers)
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
            ->whereColumn('viewer_copies.team_id', 'emails.team_id')
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
            ->whereColumn('share_source_emails.team_id', 'emails.team_id')
            ->whereColumn('share_source_emails.rfc_message_id', 'emails.rfc_message_id')
            ->whereNotNull('emails.rfc_message_id');

        if ($tiers !== null) {
            $query->whereIn('email_shares.tier', $tiers);
        }

        return $query;
    }
}
