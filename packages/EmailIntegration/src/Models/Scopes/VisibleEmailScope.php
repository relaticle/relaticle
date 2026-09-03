<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models\Scopes;

use App\Models\Team;
use App\Models\User;
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
        $teamId = $this->viewer->current_team_id;

        $builder
            ->where('team_id', $teamId)
            ->where(function (Builder $visibilityQuery) use ($viewerId, $teamId): void {
                // Owner always sees their own emails
                $visibilityQuery->where('user_id', $viewerId)
                    ->orWhere(function (Builder $sharedQuery) use ($viewerId, $teamId): void {
                        $sharedQuery->where(function (Builder $publicGate) use ($teamId): void {
                            $publicGate->where('is_internal', false)
                                ->where('privacy_tier', '!=', EmailPrivacyTier::PRIVATE->value);

                            // Mirror PrivacyService::effectiveTier() teammate visibility rules so
                            // inbox lists and policy checks agree.
                            $this->excludeTeammateHiddenEmails($publicGate, $teamId);
                        })->orWhereHas('shares', fn (Builder $shareQuery) => $shareQuery->where('shared_with', $viewerId));
                    });
            });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeTeammateHiddenEmails(Builder $builder, ?string $teamId): void
    {
        if ($teamId === null) {
            return;
        }

        $team = Team::query()->find($teamId);

        if ($team === null) {
            return;
        }

        $visibility = resolve(EmailVisibilityService::class);
        $memberEmails = $visibility->memberEmailsForTeam($team);
        $protectedDomains = $visibility->workspaceDomains($team);

        $this->excludeEmailsWithBlockedParticipant($builder, $teamId);
        $this->excludeEmailsWhereAllParticipantsAreProtected($builder, $teamId, $memberEmails, $protectedDomains);
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeEmailsWithBlockedParticipant(Builder $builder, string $teamId): void
    {
        $builder->whereDoesntHave('participants', function (Builder $participantQuery) use ($teamId): void {
            $participantQuery->where(function (Builder $match) use ($teamId): void {
                $match->whereExists(function (BaseBuilder $blockedEmail) use ($teamId): void {
                    $blockedEmail->from('team_email_blocklists')
                        ->where('team_email_blocklists.team_id', $teamId)
                        ->where('team_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('team_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                        ->whereRaw('lower(team_email_blocklists.value) = lower(email_participants.email_address)');
                })->orWhereExists(function (BaseBuilder $blockedDomain) use ($teamId): void {
                    $blockedDomain->from('team_email_blocklists')
                        ->where('team_email_blocklists.team_id', $teamId)
                        ->where('team_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('team_email_blocklists.type', EmailBlocklistType::DOMAIN->value)
                        ->whereRaw("lower(email_participants.email_address) like '%@' || lower(team_email_blocklists.value)");
                });
            });
        });
    }

    /**
     * @param  list<lowercase-string>  $memberEmails
     * @param  list<lowercase-string>  $protectedDomains
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
                                $protectedEmail->from('team_email_blocklists')
                                    ->where('team_email_blocklists.team_id', $teamId)
                                    ->where('team_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('team_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                                    ->whereRaw('lower(team_email_blocklists.value) = lower(email_participants.email_address)');
                            })
                            ->whereNotExists(function (BaseBuilder $protectedDomain) use ($teamId): void {
                                $protectedDomain->from('team_email_blocklists')
                                    ->where('team_email_blocklists.team_id', $teamId)
                                    ->where('team_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('team_email_blocklists.type', EmailBlocklistType::DOMAIN->value)
                                    ->whereRaw("lower(email_participants.email_address) like '%@' || lower(team_email_blocklists.value)");
                            });
                    });
                });
        });
    }
}
