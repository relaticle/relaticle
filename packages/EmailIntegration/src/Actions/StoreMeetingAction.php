<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Data\NormalizedMeetingPayload;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

final readonly class StoreMeetingAction
{
    public function __construct(
        private LinkMeetingAction $linkMeeting,
    ) {}

    public function execute(NormalizedMeetingPayload $payload, ConnectedAccount $account): ?Meeting
    {
        if ($this->shouldSkip($payload)) {
            $this->softDeleteCancelledMeetings($account, $payload);

            return null;
        }

        $isNewMeeting = false;

        $meeting = DB::transaction(function () use ($payload, $account, &$isNewMeeting): Meeting {
            $meeting = Meeting::withTrashed()
                ->where('connected_account_id', $account->getKey())
                ->where('provider_event_id', $payload->providerEventId)
                ->first();

            $responseStatus = $this->resolveSelfResponseStatus($payload, $account, $meeting);

            $attributes = [
                'team_id' => $account->team_id,
                'connected_account_id' => $account->getKey(),
                'provider_event_id' => $payload->providerEventId,
                'provider_recurring_event_id' => $payload->providerRecurringEventId,
                'ical_uid' => $payload->icalUid,
                'title' => $payload->title,
                'description' => $payload->description,
                'location' => $payload->location,
                'starts_at' => $payload->startsAt,
                'ends_at' => $payload->endsAt,
                'all_day' => $payload->allDay,
                'organizer_email' => $payload->organizerEmail,
                'organizer_name' => $payload->organizerName,
                'status' => $payload->status,
                'visibility' => $payload->visibility,
                'response_status' => $responseStatus,
                'html_link' => $payload->htmlLink,
                'deleted_at' => null,
            ];

            $isNewMeeting = ! ($meeting instanceof Meeting);

            if ($meeting instanceof Meeting) {
                $meeting->fill($attributes)->save();
            } else {
                $meeting = Meeting::query()->create($attributes);
            }

            $meeting->attendees()->delete();

            foreach ($payload->attendees as $attendee) {
                $meeting->attendees()->create([
                    'email_address' => $attendee->emailAddress,
                    'name' => $attendee->name,
                    'response_status' => $attendee->isSelf
                        ? ($attendee->responseStatus ?? $responseStatus)
                        : $attendee->responseStatus,
                    'is_organizer' => $attendee->isOrganizer,
                    'is_self' => $attendee->isSelf,
                ]);
            }

            $this->linkMeeting->execute($meeting);

            // Logged here (not in MeetingObserver::created) because the attendee_count is
            // only correct after attendees are inserted above. The observer fires on the
            // bare Meeting::create() before any attendee exists, recording 0.
            if ($isNewMeeting) {
                activity()
                    ->performedOn($meeting)
                    ->withProperties([
                        'title' => $meeting->title,
                        'starts_at' => $meeting->starts_at->toIso8601String(),
                        'attendee_count' => count($payload->attendees),
                    ])
                    ->event('meeting.created')
                    ->log('meeting.created');
            }

            return $meeting;
        });

        if ($isNewMeeting) {
            $this->bumpInitialCalendarImportProgress($account);
        }

        return $meeting;
    }

    private function resolveSelfResponseStatus(
        NormalizedMeetingPayload $payload,
        ConnectedAccount $account,
        ?Meeting $meeting,
    ): ?AttendeeResponseStatus {
        if ($payload->selfResponseStatus instanceof AttendeeResponseStatus) {
            return $payload->selfResponseStatus;
        }

        if ($meeting instanceof Meeting && $meeting->response_status instanceof AttendeeResponseStatus) {
            return $meeting->response_status;
        }

        $organizerEmail = $payload->organizerEmail;

        if ($organizerEmail !== null && strtolower($organizerEmail) === strtolower($account->email_address)) {
            return AttendeeResponseStatus::ACCEPTED;
        }

        return null;
    }

    private function shouldSkip(NormalizedMeetingPayload $payload): bool
    {
        if ($payload->visibility->isPrivate()) {
            return true;
        }

        return $payload->status === CalendarEventStatus::CANCELLED;
    }

    private function softDeleteCancelledMeetings(ConnectedAccount $account, NormalizedMeetingPayload $payload): void
    {
        Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->where(function (Builder $query) use ($payload): void {
                $query->where('provider_event_id', $payload->providerEventId);

                if ($this->isSeriesMasterPayload($payload)) {
                    $query->orWhere('provider_recurring_event_id', $payload->providerEventId);
                }
            })
            ->delete();
    }

    private function isSeriesMasterPayload(NormalizedMeetingPayload $payload): bool
    {
        return $payload->providerRecurringEventId === null || $payload->providerRecurringEventId === '';
    }

    private function bumpInitialCalendarImportProgress(ConnectedAccount $connectedAccount): void
    {
        if ($connectedAccount->calendar_sync_cursor === null) {
            ConnectedAccount::query()
                ->whereKey($connectedAccount->getKey())
                ->whereNull('calendar_sync_cursor')
                ->increment('initial_calendar_sync_imported');
        }

        if (MailboxSyncTracker::isCalendarSyncing($connectedAccount)) {
            MailboxSyncTracker::bumpCalendarProcessed($connectedAccount);
        }
    }
}
