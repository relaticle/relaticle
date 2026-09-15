<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models\Scopes;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

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
    public function __construct(private User $viewer) {}

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $viewerId = $this->viewer->getKey();
        $teamId = $this->viewer->current_workspace_id;

        $builder
            ->where('workspace_id', $teamId)
            ->where(function (Builder $visibilityQuery) use ($viewerId, $teamId): void {
                $this->excludeEmailsMatchingMailboxBlocklist($visibilityQuery);

                if ($teamId !== null) {
                    $this->excludeEmailsWithBlockedParticipant($visibilityQuery, $teamId);
                }

                // Owner sees their own emails unless a Blocked or mailbox-only rule applies above.
                $visibilityQuery->where(function (Builder $ownerOrShared) use ($viewerId, $teamId): void {
                    $ownerOrShared->where('user_id', $viewerId)
                        ->orWhere(function (Builder $sharedQuery) use ($viewerId, $teamId): void {
                            $this->whereTeammateMaySeeMetadata($sharedQuery, $viewerId, $teamId);
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
    private function whereTeammateMaySeeMetadata(Builder $builder, string $viewerId, ?string $teamId): Builder
    {
        $visibleTiers = [
            EmailPrivacyTier::METADATA_ONLY->value,
            EmailPrivacyTier::SUBJECT->value,
            EmailPrivacyTier::FULL->value,
        ];

        return $builder->where(function (Builder $access) use ($viewerId, $teamId, $visibleTiers): void {
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
                ->orWhere(function (Builder $byDefault) use ($viewerId, $teamId, $visibleTiers): void {
                    $byDefault
                        ->where(function (Builder $publicGate) use ($teamId): void {
                            $publicGate->where('is_internal', false);

                            $this->excludeTeammateHiddenEmails($publicGate, $teamId);
                        })
                        ->whereIn('privacy_tier', $visibleTiers)
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
    private function excludeTeammateHiddenEmails(Builder $builder, ?string $teamId): void
    {
        if ($teamId === null) {
            return;
        }

        $team = Workspace::query()->find($teamId);

        if ($team === null) {
            return;
        }

        $visibility = resolve(EmailVisibilityService::class);
        $memberEmails = $visibility->memberEmailsForTeam($team);
        $protectedDomains = $visibility->workspaceDomains($team);

        $this->excludeEmailsWhereAllParticipantsAreProtected($builder, $teamId, $memberEmails, $protectedDomains);
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
                        ->where('email_blocklists.type', EmailBlocklistType::DOMAIN->value)
                        ->whereRaw("lower(email_participants.email_address) like '%@' || lower(email_blocklists.value)");
                });
            });
        });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeEmailsWithBlockedParticipant(Builder $builder, string $teamId): void
    {
        $builder->whereDoesntHave('participants', function (Builder $participantQuery) use ($teamId): void {
            $participantQuery->where(function (Builder $match) use ($teamId): void {
                $match->whereExists(function (BaseBuilder $blockedEmail) use ($teamId): void {
                    $blockedEmail->from('workspace_email_blocklists')
                        ->where('workspace_email_blocklists.workspace_id', $teamId)
                        ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('workspace_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                        ->whereRaw('lower(workspace_email_blocklists.value) = lower(email_participants.email_address)');
                })->orWhereExists(function (BaseBuilder $blockedDomain) use ($teamId): void {
                    $blockedDomain->from('workspace_email_blocklists')
                        ->where('workspace_email_blocklists.workspace_id', $teamId)
                        ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('workspace_email_blocklists.type', EmailBlocklistType::DOMAIN->value)
                        ->whereRaw("lower(email_participants.email_address) like '%@' || lower(workspace_email_blocklists.value)");
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
        string $teamId,
        array $memberEmails,
        array $protectedDomains,
    ): void {
        $builder->where(function (Builder $visibleQuery) use ($teamId, $memberEmails, $protectedDomains): void {
            $visibleQuery
                ->doesntHave('participants')
                ->orWhereHas('participants', function (Builder $unprotectedParticipant) use ($teamId, $memberEmails, $protectedDomains): void {
                    $unprotectedParticipant->where(function (Builder $notProtected) use ($teamId, $memberEmails, $protectedDomains): void {
                        if ($memberEmails !== []) {
                            $notProtected->whereNotIn(DB::raw('lower(email_participants.email_address)'), $memberEmails);
                        }

                        foreach ($protectedDomains as $domain) {
                            $notProtected->whereRaw(
                                "lower(email_participants.email_address) not like '%@' || ?",
                                [strtolower($domain)],
                            );
                        }

                        $notProtected
                            ->whereNotExists(function (BaseBuilder $protectedEmail) use ($teamId): void {
                                $protectedEmail->from('workspace_email_blocklists')
                                    ->where('workspace_email_blocklists.workspace_id', $teamId)
                                    ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('workspace_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                                    ->whereRaw('lower(workspace_email_blocklists.value) = lower(email_participants.email_address)');
                            })
                            ->whereNotExists(function (BaseBuilder $protectedDomain) use ($teamId): void {
                                $protectedDomain->from('workspace_email_blocklists')
                                    ->where('workspace_email_blocklists.workspace_id', $teamId)
                                    ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('workspace_email_blocklists.type', EmailBlocklistType::DOMAIN->value)
                                    ->whereRaw("lower(email_participants.email_address) like '%@' || lower(workspace_email_blocklists.value)");
                            });
                    });
                });
        });
    }
}
