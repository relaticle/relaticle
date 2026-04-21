<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Data\NormalizedMeetingPayload;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class StoreMeetingAction
{
    public function __construct(
        private LinkMeetingAction $linkMeeting,
    ) {}

    public function execute(NormalizedMeetingPayload $payload, ConnectedAccount $account): ?Meeting
    {
        if ($this->shouldSkip($payload)) {
            $this->softDeleteExisting($account, $payload->providerEventId);

            return null;
        }

        $meeting = Meeting::withTrashed()
            ->where('connected_account_id', $account->getKey())
            ->where('provider_event_id', $payload->providerEventId)
            ->first();

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
            'response_status' => $payload->selfResponseStatus,
            'html_link' => $payload->htmlLink,
            'deleted_at' => null,
        ];

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
                'response_status' => $attendee->responseStatus,
                'is_organizer' => $attendee->isOrganizer,
                'is_self' => $attendee->isSelf,
            ]);
        }

        $this->linkMeeting->execute($meeting);

        return $meeting;
    }

    private function shouldSkip(NormalizedMeetingPayload $payload): bool
    {
        if ($payload->visibility->isPrivate()) {
            return true;
        }
        if ($payload->status === CalendarEventStatus::CANCELLED) {
            return true;
        }
        if ($payload->selfResponseStatus === AttendeeResponseStatus::DECLINED) {
            return true;
        }

        return $payload->startsAt->lt(Date::now()->subDays(90));
    }

    private function softDeleteExisting(ConnectedAccount $account, string $providerEventId): void
    {
        Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->where('provider_event_id', $providerEventId)
            ->delete();
    }
}
