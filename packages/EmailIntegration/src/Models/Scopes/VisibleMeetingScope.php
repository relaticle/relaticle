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
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

/**
 * Hides calendar events that workspace or mailbox visibility rules exclude.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final readonly class VisibleMeetingScope implements Scope
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
                $this->excludeMeetingsMatchingMailboxBlocklist($visibilityQuery);

                if ($teamId !== null) {
                    $this->excludeMeetingsWithBlockedAttendee($visibilityQuery, $teamId);
                }

                $visibilityQuery->where(function (Builder $ownerOrShared) use ($viewerId, $teamId): void {
                    $ownerOrShared
                        ->whereHas(
                            'connectedAccount',
                            fn (Builder $accountQuery): Builder => $accountQuery->where('user_id', $viewerId),
                        )
                        ->orWhere(function (Builder $teammateQuery) use ($teamId): void {
                            $this->excludeTeammateHiddenMeetings($teammateQuery, $teamId);
                        });
                });
            });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeTeammateHiddenMeetings(Builder $builder, ?string $teamId): void
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

        $this->excludeMeetingsWhereAllAttendeesAreProtected($builder, $teamId, $memberEmails, $protectedDomains);
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeMeetingsMatchingMailboxBlocklist(Builder $builder): void
    {
        $builder->whereDoesntHave('attendees', function (Builder $attendeeQuery): void {
            $attendeeQuery->where(function (Builder $match): void {
                $match->whereExists(function (BaseBuilder $blockedEmail): void {
                    $blockedEmail->from('email_blocklists')
                        ->whereColumn('email_blocklists.connected_account_id', 'meetings.connected_account_id')
                        ->where('email_blocklists.type', EmailBlocklistType::EMAIL->value)
                        ->whereRaw('lower(email_blocklists.value) = lower(meeting_attendees.email_address)');
                })->orWhereExists(function (BaseBuilder $blockedDomain): void {
                    $blockedDomain->from('email_blocklists')
                        ->whereColumn('email_blocklists.connected_account_id', 'meetings.connected_account_id')
                        ->where('email_blocklists.type', EmailBlocklistType::DOMAIN->value)
                        ->whereRaw("lower(meeting_attendees.email_address) like '%@' || lower(email_blocklists.value)");
                });
            });
        });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeMeetingsWithBlockedAttendee(Builder $builder, string $teamId): void
    {
        $builder->whereDoesntHave('attendees', function (Builder $attendeeQuery) use ($teamId): void {
            $attendeeQuery->where(function (Builder $match) use ($teamId): void {
                $match->whereExists(function (BaseBuilder $blockedEmail) use ($teamId): void {
                    $blockedEmail->from('team_email_blocklists')
                        ->where('team_email_blocklists.team_id', $teamId)
                        ->where('team_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('team_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                        ->whereRaw('lower(team_email_blocklists.value) = lower(meeting_attendees.email_address)');
                })->orWhereExists(function (BaseBuilder $blockedDomain) use ($teamId): void {
                    $blockedDomain->from('team_email_blocklists')
                        ->where('team_email_blocklists.team_id', $teamId)
                        ->where('team_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('team_email_blocklists.type', EmailBlocklistType::DOMAIN->value)
                        ->whereRaw("lower(meeting_attendees.email_address) like '%@' || lower(team_email_blocklists.value)");
                });
            });
        });
    }

    /**
     * @param  array<int, lowercase-string>  $memberEmails
     * @param  array<int, lowercase-string>  $protectedDomains
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeMeetingsWhereAllAttendeesAreProtected(
        Builder $builder,
        string $teamId,
        array $memberEmails,
        array $protectedDomains,
    ): void {
        $builder->where(function (Builder $visibleQuery) use ($teamId, $memberEmails, $protectedDomains): void {
            $visibleQuery
                ->doesntHave('attendees')
                ->orWhereHas('attendees', function (Builder $unprotectedAttendee) use ($teamId, $memberEmails, $protectedDomains): void {
                    $unprotectedAttendee->where(function (Builder $notProtected) use ($teamId, $memberEmails, $protectedDomains): void {
                        if ($memberEmails !== []) {
                            $notProtected->whereNotIn(DB::raw('lower(meeting_attendees.email_address)'), $memberEmails);
                        }

                        foreach ($protectedDomains as $domain) {
                            $notProtected->whereRaw(
                                "lower(meeting_attendees.email_address) not like '%@' || ?",
                                [strtolower($domain)],
                            );
                        }

                        $notProtected
                            ->whereNotExists(function (BaseBuilder $protectedEmail) use ($teamId): void {
                                $protectedEmail->from('team_email_blocklists')
                                    ->where('team_email_blocklists.team_id', $teamId)
                                    ->where('team_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('team_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                                    ->whereRaw('lower(team_email_blocklists.value) = lower(meeting_attendees.email_address)');
                            })
                            ->whereNotExists(function (BaseBuilder $protectedDomain) use ($teamId): void {
                                $protectedDomain->from('team_email_blocklists')
                                    ->where('team_email_blocklists.team_id', $teamId)
                                    ->where('team_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('team_email_blocklists.type', EmailBlocklistType::DOMAIN->value)
                                    ->whereRaw("lower(meeting_attendees.email_address) like '%@' || lower(team_email_blocklists.value)");
                            });
                    });
                });
        });
    }
}
