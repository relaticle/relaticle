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
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;
use Relaticle\EmailIntegration\Support\BlocklistDomainMatcher;

/**
 * Hides calendar events that workspace or mailbox visibility rules exclude.
 *
 * Personal calendar mode (Home) shows only meetings synced from one of the
 * viewer's connected mailboxes or where the viewer is on the guest list.
 * Workspace mode also shares teammate meetings that include an external guest.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final readonly class VisibleMeetingScope implements Scope
{
    public function __construct(
        private User $viewer,
        private bool $personalCalendarOnly = false,
    ) {}

    /**
     * @return self<Model>
     */
    public static function personal(User $viewer): self
    {
        return new self($viewer, personalCalendarOnly: true);
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $viewerId = $this->viewer->getKey();
        $workspaceId = $this->viewer->current_workspace_id;
        $identityEmails = $workspaceId !== null
            ? resolve(MeetingRespondentResolver::class)->identityEmailsForUser($this->viewer, $workspaceId)
            : [];

        $builder
            ->where($model->qualifyColumn('workspace_id'), $workspaceId)
            ->where(function (Builder $visibilityQuery) use ($viewerId, $workspaceId, $identityEmails): void {
                $this->excludeMeetingsMatchingMailboxBlocklist($visibilityQuery);
                $this->excludeMeetingsWithBlockedOrganizerMatchingMailboxBlocklist($visibilityQuery);

                if ($workspaceId !== null) {
                    $this->excludeMeetingsWithBlockedAttendee($visibilityQuery, $workspaceId);
                    $this->excludeMeetingsWithBlockedOrganizer($visibilityQuery, $workspaceId);
                }

                $visibilityQuery->where(function (Builder $ownerOrShared) use ($viewerId, $workspaceId, $identityEmails): void {
                    $ownerOrShared
                        ->whereHas(
                            'connectedAccount',
                            fn (Builder $accountQuery): Builder => $accountQuery->where('user_id', $viewerId),
                        );

                    if ($identityEmails !== []) {
                        $ownerOrShared->orWhereHas(
                            'attendees',
                            fn (Builder $attendeeQuery): Builder => $attendeeQuery->whereIn(
                                DB::raw('lower(meeting_attendees.email_address)'),
                                $identityEmails,
                            ),
                        );
                    }

                    if (! $this->personalCalendarOnly) {
                        $ownerOrShared->orWhere(function (Builder $teammateQuery) use ($workspaceId): void {
                            $this->excludeTeammateHiddenMeetings($teammateQuery, $workspaceId);
                        });
                    }
                });
            });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeTeammateHiddenMeetings(Builder $builder, ?string $workspaceId): void
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

        $this->excludeMeetingsWhereAllAttendeesAreProtected($builder, $workspaceId, $memberEmails, $protectedDomains);
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
                        ->where('email_blocklists.type', EmailBlocklistType::DOMAIN->value);
                    resolve(BlocklistDomainMatcher::class)->constrainWhereExistsDomainMatch(
                        $blockedDomain,
                        'email_blocklists.value',
                        'meeting_attendees.email_address',
                    );
                });
            });
        });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeMeetingsWithBlockedOrganizerMatchingMailboxBlocklist(Builder $builder): void
    {
        $builder->where(function (Builder $query): void {
            $query->whereNull('organizer_email')
                ->orWhere(function (Builder $visibleOrganizer): void {
                    $visibleOrganizer
                        ->whereNotExists(function (BaseBuilder $blockedEmail): void {
                            $blockedEmail->from('email_blocklists')
                                ->whereColumn('email_blocklists.connected_account_id', 'meetings.connected_account_id')
                                ->where('email_blocklists.type', EmailBlocklistType::EMAIL->value)
                                ->whereRaw('lower(email_blocklists.value) = lower(meetings.organizer_email)');
                        })
                        ->whereNotExists(function (BaseBuilder $blockedDomain): void {
                            $blockedDomain->from('email_blocklists')
                                ->whereColumn('email_blocklists.connected_account_id', 'meetings.connected_account_id')
                                ->where('email_blocklists.type', EmailBlocklistType::DOMAIN->value);
                            resolve(BlocklistDomainMatcher::class)->constrainWhereExistsDomainMatch(
                                $blockedDomain,
                                'email_blocklists.value',
                                'meetings.organizer_email',
                            );
                        });
                });
        });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeMeetingsWithBlockedOrganizer(Builder $builder, string $workspaceId): void
    {
        $builder->where(function (Builder $query) use ($workspaceId): void {
            $query->whereNull('organizer_email')
                ->orWhere(function (Builder $visibleOrganizer) use ($workspaceId): void {
                    $visibleOrganizer
                        ->whereNotExists(function (BaseBuilder $blockedEmail) use ($workspaceId): void {
                            $blockedEmail->from('workspace_email_blocklists')
                                ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                                ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                                ->where('workspace_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                                ->whereRaw('lower(workspace_email_blocklists.value) = lower(meetings.organizer_email)');
                        })
                        ->whereNotExists(function (BaseBuilder $blockedDomain) use ($workspaceId): void {
                            $blockedDomain->from('workspace_email_blocklists')
                                ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                                ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                                ->where('workspace_email_blocklists.type', EmailBlocklistType::DOMAIN->value);
                            resolve(BlocklistDomainMatcher::class)->constrainWhereExistsDomainMatch(
                                $blockedDomain,
                                'workspace_email_blocklists.value',
                                'meetings.organizer_email',
                            );
                        });
                });
        });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeMeetingsWithBlockedAttendee(Builder $builder, string $workspaceId): void
    {
        $builder->whereDoesntHave('attendees', function (Builder $attendeeQuery) use ($workspaceId): void {
            $attendeeQuery->where(function (Builder $match) use ($workspaceId): void {
                $match->whereExists(function (BaseBuilder $blockedEmail) use ($workspaceId): void {
                    $blockedEmail->from('workspace_email_blocklists')
                        ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                        ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('workspace_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                        ->whereRaw('lower(workspace_email_blocklists.value) = lower(meeting_attendees.email_address)');
                })->orWhereExists(function (BaseBuilder $blockedDomain) use ($workspaceId): void {
                    $blockedDomain->from('workspace_email_blocklists')
                        ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                        ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Blocked->value)
                        ->where('workspace_email_blocklists.type', EmailBlocklistType::DOMAIN->value);
                    resolve(BlocklistDomainMatcher::class)->constrainWhereExistsDomainMatch(
                        $blockedDomain,
                        'workspace_email_blocklists.value',
                        'meeting_attendees.email_address',
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
    private function excludeMeetingsWhereAllAttendeesAreProtected(
        Builder $builder,
        string $workspaceId,
        array $memberEmails,
        array $protectedDomains,
    ): void {
        $builder->where(function (Builder $visibleQuery) use ($workspaceId, $memberEmails, $protectedDomains): void {
            $visibleQuery
                ->doesntHave('attendees')
                ->orWhereHas('attendees', function (Builder $unprotectedAttendee) use ($workspaceId, $memberEmails, $protectedDomains): void {
                    $unprotectedAttendee->where(function (Builder $notProtected) use ($workspaceId, $memberEmails, $protectedDomains): void {
                        if ($memberEmails !== []) {
                            $notProtected->whereNotIn(DB::raw('lower(meeting_attendees.email_address)'), $memberEmails);
                        }

                        foreach ($protectedDomains as $domain) {
                            $notProtected->whereRaw(
                                "lower(meeting_attendees.email_address) not like '%@' || ? escape '\\'",
                                [resolve(BlocklistDomainMatcher::class)->escapeLike(strtolower($domain))],
                            );
                        }

                        $notProtected
                            ->whereNotExists(function (BaseBuilder $protectedEmail) use ($workspaceId): void {
                                $protectedEmail->from('workspace_email_blocklists')
                                    ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                                    ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('workspace_email_blocklists.type', EmailBlocklistType::EMAIL->value)
                                    ->whereRaw('lower(workspace_email_blocklists.value) = lower(meeting_attendees.email_address)');
                            })
                            ->whereNotExists(function (BaseBuilder $protectedDomain) use ($workspaceId): void {
                                $protectedDomain->from('workspace_email_blocklists')
                                    ->where('workspace_email_blocklists.workspace_id', $workspaceId)
                                    ->where('workspace_email_blocklists.enforcement_level', EmailVisibilityEnforcement::Protected->value)
                                    ->where('workspace_email_blocklists.type', EmailBlocklistType::DOMAIN->value);
                                resolve(BlocklistDomainMatcher::class)->constrainWhereExistsDomainMatch(
                                    $protectedDomain,
                                    'workspace_email_blocklists.value',
                                    'meeting_attendees.email_address',
                                );
                            });
                    });
                });
        });
    }
}
