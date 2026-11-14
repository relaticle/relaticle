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
    public function __construct(private User $viewer, ?bool $appliesMailboxBlocklist = null, ?bool $appliesWorkspaceBlocklist = null)
    {
        $workspaceId = $viewer->current_workspace_id;

        $this->appliesMailboxBlocklist = $appliesMailboxBlocklist ?? self::appliesMailboxBlocklist($workspaceId);
        $this->appliesWorkspaceBlocklist = $appliesWorkspaceBlocklist ?? self::appliesWorkspaceBlocklist($workspaceId);
    }

    /** @return self<Model> */
    public function forTeammate(User $teammate): self
    {
        return new self($teammate, $this->appliesMailboxBlocklist, $this->appliesWorkspaceBlocklist);
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
                self::excludeBlockedEmails($visibilityQuery, $workspaceId, $this->appliesMailboxBlocklist, $this->appliesWorkspaceBlocklist);

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
                ->whereHas('shares', fn (Builder $shareQuery): Builder => $shareQuery
                    ->where('shared_with', $viewerId)
                    ->whereIn('tier', $visibleTiers))
                ->orWhere(function (Builder $byDefault) use ($viewerId, $workspaceId, $visibleTiers): void {
                    $byDefault
                        ->where(fn (Builder $publicGate): Builder => self::wherePublicToTeammates($publicGate, $workspaceId))
                        ->whereIn('privacy_tier', $visibleTiers)
                        ->whereExists(fn (BaseBuilder $link): BaseBuilder => $link
                            ->from('emailables')
                            ->whereColumn('emailables.email_id', 'emails.id'))
                        ->whereDoesntHave('shares', fn (Builder $shareQuery): Builder => $shareQuery
                            ->where('shared_with', $viewerId));
                });
        });
    }

    /**
     * @template TBuilder of Builder<covariant Model>
     *
     * @param  TBuilder  $builder
     * @return TBuilder
     */
    public static function wherePublicToTeammates(Builder $builder, ?string $workspaceId): Builder
    {
        $builder->where($builder->qualifyColumn('is_internal'), false);

        if ($workspaceId !== null) {
            self::excludeTeammateHiddenEmails($builder, $workspaceId);
        }

        return $builder;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private static function excludeTeammateHiddenEmails(Builder $builder, string $workspaceId): void
    {

        $visibility = resolve(EmailVisibilityService::class);
        $workspace = $visibility->workspace($workspaceId);

        if ($workspace === null) {
            return;
        }

        $memberEmails = $visibility->memberEmailsForWorkspace($workspace);
        $protectedDomains = $visibility->workspaceDomains($workspace);

        self::excludeEmailsWhereAllParticipantsAreProtected($builder, $workspaceId, $memberEmails, $protectedDomains);
    }

    /**
     * Mail from a workspace Blocked entry or the mailbox's own blocklist reaches nobody, the owner included.
     *
     * @template TBuilder of Builder<covariant Model>
     *
     * @param  TBuilder  $builder
     * @return TBuilder
     */
    public static function whereNotBlocked(Builder $builder, ?string $workspaceId): Builder
    {
        self::excludeBlockedEmails(
            $builder,
            $workspaceId,
            self::appliesMailboxBlocklist($workspaceId),
            self::appliesWorkspaceBlocklist($workspaceId),
        );

        return $builder;
    }

    /**
     * @template TBuilder of Builder<covariant Model>
     *
     * @param  TBuilder  $builder
     * @return TBuilder
     */
    public function excludeBlocked(Builder $builder): Builder
    {
        self::excludeBlockedEmails($builder, $this->viewer->current_workspace_id, $this->appliesMailboxBlocklist, $this->appliesWorkspaceBlocklist);

        return $builder;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private static function excludeBlockedEmails(Builder $builder, ?string $workspaceId, bool $mailboxRules, bool $workspaceRules): void
    {
        // Both anti-joins scan every participant row, so they run only when a rule exists.
        if ($mailboxRules) {
            self::excludeEmailsMatchingMailboxBlocklist($builder);
        }

        if ($workspaceId !== null && $workspaceRules) {
            self::excludeEmailsWithBlockedParticipant($builder, $workspaceId);
        }
    }

    private static function appliesMailboxBlocklist(?string $workspaceId): bool
    {
        return $workspaceId === null || self::workspaceHasMailboxBlocklist($workspaceId);
    }

    private static function appliesWorkspaceBlocklist(?string $workspaceId): bool
    {
        return $workspaceId !== null && self::workspaceHasBlockedEntry($workspaceId);
    }

    private static function workspaceHasMailboxBlocklist(string $workspaceId): bool
    {
        return EmailBlocklist::query()
            ->whereIn('connected_account_id', ConnectedAccount::withTrashed()->where('workspace_id', $workspaceId)->select('id'))
            ->exists();
    }

    private static function workspaceHasBlockedEntry(string $workspaceId): bool
    {
        return WorkspaceEmailBlocklist::query()
            ->where('workspace_id', $workspaceId)
            ->where('enforcement_level', EmailVisibilityEnforcement::Blocked)
            ->exists();
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private static function excludeEmailsMatchingMailboxBlocklist(Builder $builder): void
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
     * @param  Builder<covariant Model>  $builder
     */
    private static function excludeEmailsWithBlockedParticipant(Builder $builder, string $workspaceId): void
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
     * @param  Builder<covariant Model>  $builder
     */
    private static function excludeEmailsWhereAllParticipantsAreProtected(
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
