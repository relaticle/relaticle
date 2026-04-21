<?php

declare(strict_types=1);

use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Factories\NormalizedMeetingPayloadFactory;

mutates(StoreMeetingJob::class);

it('stores a Google event via StoreMeetingJob', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create(['email_address' => 'me@example.com']));

    $start = new EventDateTime;
    $start->setDateTime(now()->addDay()->toRfc3339String());

    $end = new EventDateTime;
    $end->setDateTime(now()->addDay()->addHour()->toRfc3339String());

    $selfAtt = new EventAttendee;
    $selfAtt->setEmail('me@example.com');
    $selfAtt->setResponseStatus('accepted');

    $event = new Event;
    $event->setId('evt-123');
    $event->setSummary('Kickoff');
    $event->setStart($start);
    $event->setEnd($end);
    $event->setAttendees([$selfAtt]);
    $event->setStatus('confirmed');
    $event->setVisibility('default');

    (new StoreMeetingJob($account, serialize($event)))->handle(
        app(StoreMeetingAction::class),
        app(NormalizedMeetingPayloadFactory::class),
    );

    expect(Meeting::query()->where('provider_event_id', 'evt-123')->exists())->toBeTrue();
});
