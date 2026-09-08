<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Contracts;

use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Services\Exceptions\MeetingResponseFailed;

interface CalendarServiceInterface
{
    public function initialSync(?string $pageToken = null): CalendarSyncResult;

    /**
     * @throws CalendarSyncTokenExpired when Google invalidates the syncToken (HTTP 410)
     */
    public function fetchDelta(string $syncToken): CalendarSyncResult;

    /**
     * Write the connected account's RSVP to the provider event.
     *
     * @throws MeetingResponseFailed when the provider rejects the update
     */
    public function respondToEvent(string $eventId, AttendeeResponseStatus $status): void;
}
