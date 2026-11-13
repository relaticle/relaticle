<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Features\EmailIntegration;
use App\Models\Company;
use App\Models\People;
use App\Support\CurrentWorkspace;
use App\Support\EmailAddress;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;

#[DeleteWhenMissingModels]
#[Queue('emails-sync')]
#[Timeout(300)]
#[UniqueFor(600)]
final class RelinkRecordHistoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly People|Company $record,
    ) {}

    public function handle(
        EmailVisibilityService $visibility,
        CompanyDomainMatcher $domainMatcher,
        LinkEmailAction $linkEmail,
        LinkMeetingAction $linkMeeting,
    ): void {
        $workspaceId = (string) $this->record->workspace_id;

        if (! self::shouldRelink($workspaceId)) {
            return;
        }

        resolve(CurrentWorkspace::class)->within($workspaceId, function () use ($visibility, $domainMatcher, $linkEmail, $linkMeeting, $workspaceId): void {
            $unlinkedParticipants = $this->unlinkedParticipantsMatcher($visibility, $domainMatcher, $workspaceId);

            if (! $unlinkedParticipants instanceof Closure) {
                return;
            }

            Email::query()
                ->where('workspace_id', $workspaceId)
                ->whereHas('participants', $unlinkedParticipants)
                ->with(['connectedAccount', 'workspace'])
                ->lazyById(100)
                ->each(fn (Email $email) => $linkEmail->reapply($email));

            Meeting::query()
                ->where('workspace_id', $workspaceId)
                ->whereHas('attendees', fn (Builder $attendeeQuery): Builder => $unlinkedParticipants($attendeeQuery)->where('is_self', false))
                ->with(['connectedAccount', 'workspace'])
                ->lazyById(100)
                ->each(fn (Meeting $meeting) => $linkMeeting->execute($meeting));
        });
    }

    public static function shouldRelink(string $workspaceId): bool
    {
        return Feature::active(EmailIntegration::class)
            && ConnectedAccount::query()->where('workspace_id', $workspaceId)->exists();
    }

    public function uniqueId(): string
    {
        return "relink-record-history-{$this->record->getMorphClass()}-{$this->record->getKey()}";
    }

    /**
     * @return (Closure(Builder): Builder)|null
     */
    private function unlinkedParticipantsMatcher(EmailVisibilityService $visibility, CompanyDomainMatcher $domainMatcher, string $workspaceId): ?Closure
    {
        if ($this->record instanceof People) {
            $addresses = array_values(array_unique(array_map(
                EmailAddress::canonicalize(...),
                $visibility->recordIdentityAddresses($this->record),
            )));

            return $addresses === [] ? null : fn (Builder $query): Builder => $query
                ->whereNull('contact_id')
                ->whereIn(DB::raw('lower(email_address)'), $addresses);
        }

        $hosts = collect($visibility->recordIdentityDomains($this->record))
            ->map(fn (string $domain): string => $domainMatcher->host($domain))
            ->diff($domainMatcher->skippedHosts($workspaceId))
            ->unique()
            ->values()
            ->all();

        return $hosts === [] ? null : fn (Builder $query): Builder => $query
            ->whereNull('company_id')
            ->whereIn(DB::raw("split_part(lower(email_address), '@', 2)"), $hosts);
    }
}
