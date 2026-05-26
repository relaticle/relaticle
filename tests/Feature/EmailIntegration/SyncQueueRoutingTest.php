<?php

declare(strict_types=1);

use App\Jobs\ClassifyEmailJob;
use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(
    ClassifyEmailJob::class,
    IncrementalEmailSyncJob::class,
    InitialEmailSyncJob::class,
    StoreEmailJob::class,
    IncrementalCalendarSyncJob::class,
    InitialCalendarSyncJob::class,
    StoreMeetingJob::class,
);

it('routes inbound email sync and classify jobs to emails-sync queue', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $event = new CalendarEventData(
        providerEventId: 'evt-queue',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Queue test',
        description: null,
        startsAt: Carbon::now(),
        endsAt: Carbon::now()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );

    expect((new IncrementalEmailSyncJob($account))->queue)->toBe('emails-sync')
        ->and((new InitialEmailSyncJob($account))->queue)->toBe('emails-sync')
        ->and((new StoreEmailJob($account, 'msg-1'))->queue)->toBe('emails-sync')
        ->and((new IncrementalCalendarSyncJob($account))->queue)->toBe('emails-sync')
        ->and((new InitialCalendarSyncJob($account))->queue)->toBe('emails-sync')
        ->and((new StoreMeetingJob($account, $event))->queue)->toBe('emails-sync')
        ->and((new ClassifyEmailJob('email-id'))->queue)->toBe('emails-sync');
});
