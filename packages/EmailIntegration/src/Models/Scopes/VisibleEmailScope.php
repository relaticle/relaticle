<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Support\BlocklistDomainMatcher;

/**
 * Excludes emails that are entirely private to another user.
 * Fine-grained field masking happens at the view/policy layer.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final readonly class VisibleEmailScope implements Scope
{
    private bool $appliesMailboxBlocklist;

    private bool $appliesWorkspaceBlocklist;

    // Builders apply a scope once per compile, so the rule lookups run here, once per query.
    public function __construct(private User $viewer)
    {
        $workspaceId = $viewer->current_workspace_id;

        $this->appliesMailboxBlocklist = $workspaceId === null || $this->workspaceHasMailboxBlocklist($workspaceId);
        $this->appliesWorkspaceBlocklist = $workspaceId !== null && $this->workspaceHasBlockedEntry($workspaceId);
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $viewerId = $this->viewer->getKey();
        $workspaceId = $this->viewer->current_workspace_id;

        $builder
            ->where('workspace_id', $workspaceId)
            ->where(function (Builder $visibilityQuery) use ($viewerId, $workspaceId): void {
                // Both anti-joins scan every participant row, so they run only when a rule exists.
                if ($this->appliesMailboxBlocklist) {
                    $this->excludeEmailsMatchingMailboxBlocklist($visibilityQuery);
                }

                if ($workspaceId !== null && $this->appliesWorkspaceBlocklist) {
                    $this->excludeEmailsWithBlockedParticipant($visibilityQuery, $workspaceId);
                }

                // Owner sees their own emails unless a Blocked or mailbox-only rule applies above.
                $visibilityQuery->where(function (Builder $ownerOrShared) use ($viewerId, $workspaceId): void {
                    $ownerOrShared->where('user_id', $viewerId)
                        ->orWhere(function (Builder $sharedQuery) use ($viewerId, $workspaceId): void {
                            $this->whereTeammateMaySeeMetadata($sharedQuery, $viewerId, $workspaceId);
                        });
                });
            });
    }

    /**
     * Match PrivacyService::effectiveTier(): per-viewer share overrides the email default.
     * PRIVATE on either path hides the row entirely (including participant metadata).
     *
     * @param  Builder<covariant TModel>  $builder
     * @return Builder<covariant TModel>
     */
    private function whereTeammateMaySeeMetadata(Builder $builder, string $viewerId, ?string $workspaceId): Builder
    {
        $visibleTiers = [
            EmailPrivacyTier::METADATA_ONLY->value,
            EmailPrivacyTier::SUBJECT->value,
            EmailPrivacyTier::FULL->value,
        ];

        return $builder->where(function (Builder $access) use ($viewerId, $workspaceId, $visibleTiers): void {
            $access
                ->whereExists(fn (BaseBuilder $copyQuery): BaseBuilder => $this->syncedCopyExists($copyQuery, $viewerId))
                ->orWhereHas('shares', fn (Builder $shareQuery): Builder => $shareQuery
                    ->where('shared_with', $viewerId)
                    ->whereIn('tier', $visibleTiers))
                ->orWhere(function (Builder $crossShare) use ($viewerId, $visibleTiers): void {
                    $crossShare
                        ->whereDoesntHave('shares', fn (Builder $shareQuery): Builder => $shareQuery
                            ->where('shared_with', $viewerId))
                        ->whereExists(fn (BaseBuilder $shareQuery): BaseBuilder => $this->crossMessageShareExists(
                            $shareQuery,
                            $viewerId,
                            $visibleTiers,
                        ));
                })
                ->orWhere(function (Builder $byDefault) use ($viewerId, $workspaceId, $visibleTiers): void {
                    $byDefault
                        ->where(function (Builder $publicGate) use ($workspaceId): void {
                            $publicGate->where('is_internal', false);

                            $this->excludeTeammateHiddenEmails($publicGate, $workspaceId);
                        })
                        ->whereIn('privacy_tier', $visibleTiers)
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

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeTeammateHiddenEmails(Builder $builder, ?string $workspaceId): void
    {
        if ($workspaceId === null) {
            return;
        }

        $visibility = resolve(EmailVisibilityService::class);
        $workspace = $visibility->workspace($workspaceId);

        if ($workspace === null) {
            return;
        }

        $memberEmails = $visibility->memberEmailsForWorkspace($workspace);
        $protectedDomains = $visibility->workspaceDomains($workspace);

        $this->excludeEmailsWhereAllParticipantsAreProtected($builder, $workspaceId, $memberEmails, $protectedDomains);
    }

    private function workspaceHasMailboxBlocklist(string $workspaceId): bool
    {
        return EmailBlocklist::query()
            ->whereIn('connected_account_id', ConnectedAccount::withTrashed()->where('workspace_id', $workspaceId)->select('id'))
            ->exists();
    }

    private function workspaceHasBlockedEntry(string $workspaceId): bool
    {
        return WorkspaceEmailBlocklist::query()
            ->where('workspace_id', $workspaceId)
            ->where('enforcement_level', EmailVisibilityEnforcement::Blocked)
            ->exists();
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeEmailsMatchingMailboxBlocklist(Builder $builder): void
    {
        $builder->whereDoesntHave('participants', function (Builder $participantQuery): void {
            $participantQuery->where(function (Builder $match): void {
                $match->whereExists(function (BaseBuilder $blockedEmail): void {
                    $blockedEmail->from('email_blocklists')
                        ->whereColumn('email_blocklists.connected_account_id', 'emails.connected_account_id')
                        ->where('email_blocklists.type', EmailBlocklistType::EMAIL->value)
                        ->whereRaw('lower(email_blocklists.value) = lower(email_participants.email_address)');
                })->orWhereExists(function (BaseBuilder $blockedDomain): void {
                    $blockedDomain->from('email_blocklists')
                        ->whereColumn('email_blocklists.connected_account_id', 'emails.connected_account_id')
                        ->where('email_blocklists.type', EmailBlocklistType::DOMAIN->value);
                    resolve(BlocklistDomainMatcher::class)->constrainWhereExistsDomainMatch(
                        $blockedDomain,
                        'email_blocklists.value',
                        'email_participants.email_address',
                    );
                });
            });
        });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeEmailsWithBlockedParticipant(Builder $builder, string $workspaceId): void
    {
        $builder->whereDoesntHave('participants', function (Builder $participantQuery) use ($workspaceId): void {
            $participantQuery->where(function (Builder $match) use ($workspaceId): void {
                $match->whereExists(function (BaseBuilder $blockedEmail) use ($workspaceId): void {
                    $blockedEmail->from('workspace_email_blocklists')
                        ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                        ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('workspace_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                        ->whereRaw('lower(workspace_email_blocklists.value) = lower(email_participants.email_address)');
                })->orWhereExists(function (BaseBuilder $blockedDomain) use ($workspaceId): void {
                    $blockedDomain->from('workspace_email_blocklists')
                        ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                        ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('workspace_email_blocklists.type', EmailBlocklistType::DOMAIN->value);
                    resolve(BlocklistDomainMatcher::class)->constrainWhereExistsDomainMatch(
                        $blockedDomain,
                        'workspace_email_blocklists.value',
                        'email_participants.email_address',
                    );
                });
            });
        });
    }

    /**
     * @param  array<int, lowercase-string>  $memberEmails
     * @param  array<int, lowercase-string>  $protectedDomains
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeEmailsWhereAllParticipantsAreProtected(
        Builder $builder,
        string $workspaceId,
        array $memberEmails,
        array $protectedDomains,
    ): void {
        $builder->where(function (Builder $visibleQuery) use ($workspaceId, $memberEmails, $protectedDomains): void {
            $visibleQuery
                ->doesntHave('participants')
                ->orWhereHas('participants', function (Builder $unprotectedParticipant) use ($workspaceId, $memberEmails, $protectedDomains): void {
                    $unprotectedParticipant->where(function (Builder $notProtected) use ($workspaceId, $memberEmails, $protectedDomains): void {
                        if ($memberEmails !== []) {
                            $notProtected->whereNotIn(DB::raw('lower(email_participants.email_address)'), $memberEmails);
                        }

                        foreach ($protectedDomains as $domain) {
                            $notProtected->whereRaw(
                                "lower(email_participants.email_address) not like '%@' || ? escape '\\'",
                                [resolve(BlocklistDomainMatcher::class)->escapeLike(strtolower($domain))],
                            );
                        }

                        $notProtected
                            ->whereNotExists(function (BaseBuilder $protectedEmail) use ($workspaceId): void {
                                $protectedEmail->from('workspace_email_blocklists')
                                    ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                                    ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('workspace_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                                    ->whereRaw('lower(workspace_email_blocklists.value) = lower(email_participants.email_address)');
                            })
                            ->whereNotExists(function (BaseBuilder $protectedDomain) use ($workspaceId): void {
                                $protectedDomain->from('workspace_email_blocklists')
                                    ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                                    ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('workspace_email_blocklists.type', EmailBlocklistType::DOMAIN->value);
                                resolve(BlocklistDomainMatcher::class)->constrainWhereExistsDomainMatch(
                                    $protectedDomain,
                                    'workspace_email_blocklists.value',
                                    'email_participants.email_address',
                                );
                            });
                    });
                });
        });
    }
}
