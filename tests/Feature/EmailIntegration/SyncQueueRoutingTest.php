<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;

mutates(
    IncrementalEmailSyncJob::class,
    InitialEmailSyncJob::class,
    StoreEmailJob::class,
    IncrementalCalendarSyncJob::class,
    InitialCalendarSyncJob::class,
    StoreMeetingJob::class,
);

it('routes inbound email and calendar sync jobs to emails-sync queue', function (): void {
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
        ->and((new StoreMeetingJob($account, $event))->queue)->toBe('emails-sync');
});

it('dispatches the initial-sync StoreEmailJob batch onto the emails-sync queue', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn([
        'cursor' => 'cursor-1',
        'message_ids' => collect(['M1', 'M2']),
    ]);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialEmailSyncJob($account))->handle($factory);

    // Bus::batch() ignores each job's constructor onQueue() — without an explicit
    // ->onQueue() on the batch the StoreEmailJobs leak onto the default queue.
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === 'emails-sync'
        && $batch->jobs->count() === 2
    );
});
