<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Actions\RetryMailboxHistoryImportFailuresAction;
use Relaticle\EmailIntegration\Actions\StartMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

mutates(
    CompleteMailboxHistoryImportAction::class,
    EmailAccessNotificationHandler::class,
    IncrementalCalendarSyncJob::class,
    InitialCalendarSyncJob::class,
    InitialEmailSyncJob::class,
    MailboxHistoryImportCompletedNotification::class,
    MailboxHistoryImportService::class,
    MailboxSyncTracker::class,
    RetryMailboxHistoryImportFailuresAction::class,
    StartMailboxHistoryImportAction::class,
    StoreEmailJob::class,
);

function mailboxImportNotificationUser(): User
{
    return User::factory()->withWorkspace()->create();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mailboxImportNotificationAccount(User $user, array $attributes = []): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->current_workspace_id,
        'sync_inbox' => true,
        ...$attributes,
    ]));
}

function mailboxImportFetchedEmail(string $providerMessageId): FetchedEmailData
{
    return new FetchedEmailData(
        providerMessageId: $providerMessageId,
        rfcMessageId: "<{$providerMessageId}@example.com>",
        threadId: "thread-{$providerMessageId}",
        inReplyTo: null,
        subject: 'Quarterly review',
        snippet: 'Quarterly review',
        sentAt: now(),
        direction: EmailDirection::INBOUND,
        folder: EmailFolder::Inbox,
        hasAttachments: false,
        isRead: true,
        bodyText: 'Quarterly review',
        bodyHtml: '<p>Quarterly review</p>',
        participants: [
            ['email_address' => 'sender@example.com', 'name' => 'Sender', 'role' => 'from'],
        ],
        attachments: [],
    );
}

function mailboxImportCalendarEvent(string $providerEventId): CalendarEventData
{
    return new CalendarEventData(
        providerEventId: $providerEventId,
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Pipeline review',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );
}

/**
 * @param  list<string>  $messageIds
 */
function bindMailboxImportMailService(array $messageIds, callable $fetchMessage): void
{
    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')
        ->andReturn(new MailBackfillPage(
            messageIds: collect($messageIds),
            nextPageToken: null,
            cursor: 'history-done',
        ));
    $fetchMessage($service);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);
}

/**
 * @param  list<CalendarEventData>  $events
 */
function bindMailboxImportCalendarService(
    array $events = [],
    ?Throwable $initialSyncException = null,
    ?Throwable $fetchDeltaException = null,
): void {
    $service = Mockery::mock(CalendarServiceInterface::class);

    if ($fetchDeltaException instanceof Throwable) {
        $service->shouldReceive('fetchDelta')->andThrow($fetchDeltaException);
    } else {
        $service->shouldReceive('fetchDelta')->andReturn(new CalendarSyncResult(
            events: $events,
            nextSyncToken: 'calendar-done',
        ));
    }

    if ($initialSyncException instanceof Throwable) {
        $service->shouldReceive('initialSync')->andThrow($initialSyncException);
    } else {
        $service->shouldReceive('initialSync')->andReturn(new CalendarSyncResult(
            events: $events,
            nextSyncToken: 'calendar-done',
        ));
    }

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(CalendarServiceFactoryInterface::class, $factory);
}

function workMailboxImportQueueOnce(?int $tries = null): void
{
    $parameters = [
        'connection' => 'database',
        '--queue' => 'emails-sync',
        '--once' => true,
        '--sleep' => 0,
        '--stop-when-empty' => true,
    ];

    if ($tries !== null) {
        $parameters['--tries'] = $tries;
    }

    Artisan::call('queue:work', $parameters);

    test()->travel(20)->minutes();
}

function workMailboxImportQueueUntilEmpty(int $maxJobs = 50, ?int $tries = null): void
{
    $processed = 0;

    while ($processed < $maxJobs && DB::table('jobs')->where('queue', 'emails-sync')->exists()) {
        workMailboxImportQueueOnce($tries);
        $processed++;
    }
}

function mailboxImportQueueContains(string $jobClass): bool
{
    return DB::table('jobs')->where('queue', 'emails-sync')->get()->contains(function (object $job) use ($jobClass): bool {
        $payload = json_decode((string) $job->payload, true);

        return is_array($payload) && str_contains((string) ($payload['displayName'] ?? ''), class_basename($jobClass));
    });
}

function retryMailboxImportFromNotification(ConnectedAccount $account): void
{
    $batchId = $account->history_import_batch_id;
    expect($batchId)->toBeString()->not->toBe('');

    Livewire::test(EmailAccessNotificationHandler::class)
        ->dispatch('retry-mailbox-history-import', accountId: (string) $account->getKey(), batchId: $batchId)
        ->assertNotified(__('filament/notifications/mailbox-import-complete.retry.queued.title'));
}

/**
 * @param  array<string, mixed>  $data
 */
function assertMailboxImportRetryAction(array $data, ConnectedAccount $account, string $batchId): void
{
    $actions = collect($data['actions'] ?? [])->keyBy('name');

    expect($actions->keys()->all())->toBe(['retry'])
        ->and($actions['retry']['label'])->toBe(__('filament/notifications/mailbox-import-complete.actions.retry'))
        ->and($actions['retry']['event'])->toBe('retry-mailbox-history-import')
        ->and($actions['retry']['eventData'])->toBe([
            'accountId' => (string) $account->getKey(),
            'batchId' => $batchId,
        ]);
}

function assertMailboxImportMail(User $user, MailboxHistoryImportCompletedNotification $notification, string $subject, string $line): void
{
    $mail = $notification->toMail($user);

    expect($mail->subject)->toBe($subject)
        ->and((string) $mail->render())->toContain($line)
        ->and($mail)->toBeInstanceOf(MailMessage::class);
}

it('notifies with persisted email counts when the import succeeds', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user);

    bindMailboxImportMailService(['ok-1', 'ok-2'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
        $service->shouldReceive('fetchMessage')->with('ok-2')->andReturn(mailboxImportFetchedEmail('ok-2'));
    });

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty();

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();
    $imported = __('filament/notifications/mailbox-import-complete.imported_without_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 2, ['count' => 2]),
    ]);

    expect($account->fresh()->emails()->count())->toBe(2)
        ->and($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title'))
        ->and($notification->data['status'])->toBe('success')
        ->and($notification->data['viewData']['kind'])->toBe('complete')
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]))
        ->and($notification->data['body'])->not->toContain('calendar')
        ->and($notification->data['actions'] ?? [])->toBe([]);

    assertMailboxImportMail(
        $user,
        new MailboxHistoryImportCompletedNotification($account->fresh(), $account->history_import_batch_id),
        __('filament/notifications/mailbox-import-complete.mail.subject'),
        __('filament/notifications/mailbox-import-complete.mail.line', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]),
    );
});

it('notifies with issues when some store jobs permanently fail', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
        $service->shouldReceive('fetchMessage')->with('fail-1')->andThrow(new RuntimeException('Provider unavailable'));
    });

    $ok = new StoreEmailJob($account, 'ok-1');
    $ok->tries = 1;
    $fail = new StoreEmailJob($account, 'fail-1');
    $fail->tries = 1;
    Bus::findBatch($batchId)->add([$ok, $fail]);
    workMailboxImportQueueUntilEmpty();

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();
    $imported = __('filament/notifications/mailbox-import-complete.imported_without_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 1, ['count' => 1]),
    ]);
    $failures = trans_choice('filament/notifications/mailbox-import-complete.failed_messages', 1, ['count' => 1]);

    expect($account->emails()->count())->toBe(1)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title_with_issues'))
        ->and($notification->data['status'])->toBe('warning')
        ->and($notification->data['viewData']['kind'])->toBe('partial')
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body_with_issues', [
            'imported' => $imported,
            'failures' => $failures,
            'email' => $account->email_address,
        ]));

    assertMailboxImportRetryAction($notification->data, $account, $batchId);

    livewire(EmailAccountsPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.history_import_failure.badge'))
        ->assertDontSee(__('filament/pages/email-accounts.actions.retry_failed_import.label'))
        ->assertSee(__('filament/pages/email-accounts.in_sync'));

    $mailNotification = new MailboxHistoryImportCompletedNotification($account->fresh(), $batchId, failedEmailCount: 1);
    assertMailboxImportMail(
        $user,
        $mailNotification,
        __('filament/notifications/mailbox-import-complete.mail.subject_with_issues'),
        __('filament/notifications/mailbox-import-complete.mail.line_with_issues', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => $failures,
        ]),
    );
    expect($mailNotification->toMail($user)->actionText)->toBeNull();
});

it('waits for calendar store work before sending the import summary', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService([mailboxImportCalendarEvent('evt-1')]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    $sawEmailDoneWhileCalendarPending = false;

    while (DB::table('jobs')->where('queue', 'emails-sync')->exists()) {
        workMailboxImportQueueOnce();
        $account->refresh();

        if ($account->sync_cursor !== null
            && $account->emails()->count() === 1
            && $account->calendar_sync_cursor === null
            && $user->notifications()->count() === 0) {
            $sawEmailDoneWhileCalendarPending = true;
        }
    }

    $imported = __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 1, ['count' => 1]),
        'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', 1, ['count' => 1]),
    ]);

    expect($sawEmailDoneWhileCalendarPending)->toBeTrue()
        ->and($account->fresh()->calendar_sync_cursor)->toBe('calendar-done')
        ->and($account->meetings()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title'))
        ->and($user->notifications()->sole()->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]));
});

it('reports calendar failures in the import summary', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(initialSyncException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty();

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();
    $imported = __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 1, ['count' => 1]),
        'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', 0, ['count' => 0]),
    ]);

    expect($account->emails()->count())->toBe(1)
        ->and($account->meetings()->count())->toBe(0)
        ->and($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title_with_issues'))
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body_with_issues', [
            'imported' => $imported,
            'failures' => __('filament/notifications/mailbox-import-complete.calendar_did_not_finish'),
            'email' => $account->email_address,
        ]));

    assertMailboxImportRetryAction($notification->data, $account, $notification->data['viewData']['batch_id']);
});

it('does not send a retry-success notice while a retried job still fails', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->twice()->andThrow(new RuntimeException('Provider unavailable'));
    });

    $job = new StoreEmailJob($account, 'fail-1');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    workMailboxImportQueueOnce();

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');

    retryMailboxImportFromNotification($account->fresh());

    workMailboxImportQueueOnce();

    expect($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');
});

it('sends one recovery notice after every failed import job succeeds', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->once()->ordered()->andThrow(new RuntimeException('Provider unavailable'));
        $service->shouldReceive('fetchMessage')->once()->ordered()->andReturn(mailboxImportFetchedEmail('fail-1'));
    });

    $job = new StoreEmailJob($account, 'fail-1');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    workMailboxImportQueueOnce();

    retryMailboxImportFromNotification($account->fresh());

    workMailboxImportQueueOnce();

    $notifications = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->get();

    $retrySuccess = $notifications->first(
        fn ($notification): bool => ($notification->data['viewData']['kind'] ?? null) === 'retry_success',
    );

    expect($account->emails()->count())->toBe(1)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeFalse()
        ->and($notifications)->toHaveCount(2)
        ->and($notifications->pluck('data.viewData.kind')->all())->toContain('partial', 'retry_success')
        ->and($notifications->pluck('data.title')->all())->toContain(__('filament/notifications/mailbox-import-complete.retry_success.title'))
        ->and($retrySuccess)->not->toBeNull()
        ->and($retrySuccess->data['actions'] ?? [])->toBe([]);

    $retryMail = (new MailboxHistoryImportCompletedNotification($account->fresh(), $batchId, afterFailedImportRetry: true))->toMail($user);
    expect($retryMail->subject)->toBe(__('filament/notifications/mailbox-import-complete.mail.retry_subject'));
});

it('counts persisted rows and ignores skipped store jobs', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    bindMailboxImportMailService(['ok-1', 'ok-2', 'gone-1', 'fail-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
        $service->shouldReceive('fetchMessage')->with('ok-2')->andReturn(mailboxImportFetchedEmail('ok-2'));
        $service->shouldReceive('fetchMessage')->with('gone-1')->andThrow(new GoogleServiceException('Requested entity was not found.', 404));
        $service->shouldReceive('fetchMessage')->with('fail-1')->andThrow(new RuntimeException('Provider unavailable'));
    });
    bindMailboxImportCalendarService([
        mailboxImportCalendarEvent('evt-1'),
        mailboxImportCalendarEvent('evt-2'),
    ]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty();

    $imported = __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 2, ['count' => 2]),
        'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', 2, ['count' => 2]),
    ]);
    $failures = trans_choice('filament/notifications/mailbox-import-complete.failed_messages', 1, ['count' => 1]);
    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();

    expect($account->emails()->count())->toBe(2)
        ->and($account->meetings()->count())->toBe(2)
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body_with_issues', [
            'imported' => $imported,
            'failures' => $failures,
            'email' => $account->email_address,
        ]))
        ->and($notification->data['body'])->not->toContain(trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 3, ['count' => 3]))
        ->and($notification->data['body'])->not->toContain(trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 4, ['count' => 4]));
});

it('does not complete the import while retried store jobs are still running', function (): void {
    $user = mailboxImportNotificationUser();
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    resolve(MailboxHistoryImportService::class)->markAwaitingRetrySuccessNotice($batchId);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 3,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-uuid-1', 'failed-uuid-2']),
        'finished_at' => null,
    ]);

    resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $batchId);

    expect($user->notifications()->count())->toBe(0);
});

it('does not complete the import until every batch job has finished its current attempt', function (): void {
    $user = mailboxImportNotificationUser();
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 3,
        'pending_jobs' => 2,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-uuid-1']),
        'finished_at' => null,
    ]);

    resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $batchId);

    expect($user->notifications()->count())->toBe(0);
});

it('does not send duplicate import notices from repeated completion callbacks or retry clicks', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('fail-1')->andThrow(new RuntimeException('Provider unavailable'));
    });

    $job = new StoreEmailJob($account, 'fail-1');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    workMailboxImportQueueUntilEmpty();

    resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $batchId);
    resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $batchId);

    foreach (Bus::findBatch($batchId)->options['finally'] as $callback) {
        $callback(Bus::findBatch($batchId));
    }

    expect($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1);

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeTrue();

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeFalse()
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');
});

it('does not carry calendar failures from a previous import into a new import summary', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);
    $import = resolve(MailboxHistoryImportService::class);
    $import->recordCalendarFailures((string) $account->getKey(), 5);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService([mailboxImportCalendarEvent('evt-1')]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account->fresh());
    workMailboxImportQueueUntilEmpty();

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();
    $imported = __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 1, ['count' => 1]),
        'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', 1, ['count' => 1]),
    ]);

    expect($account->fresh()->history_import_batch_id)->not->toBeNull()
        ->and($notification->data['viewData']['kind'])->toBe('complete')
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]))
        ->and($notification->data['body'])->not->toContain(trans_choice(
            'filament/notifications/mailbox-import-complete.failed_calendar_events',
            5,
            ['count' => 5],
        ));
});

it('clears calendar failures after a later calendar sync stores the missing events', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => 'history-done',
        'calendar_sync_cursor' => 'calendar-token',
    ]);
    $batchId = attachHistoryImportBatch($account);
    $import = resolve(MailboxHistoryImportService::class);
    $import->recordCalendarFailures($batchId, 2);
    $import->recordCalendarFailures((string) $account->getKey(), 2);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('fetchDelta')->once()->with('calendar-token')
        ->andReturn(new CalendarSyncResult(events: [mailboxImportCalendarEvent('evt-recovered')], nextSyncToken: 'calendar-done'));
    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);
    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    (new IncrementalCalendarSyncJob($account))->handle($factory);
    workMailboxImportQueueUntilEmpty();

    expect($import->calendarFailureCount($batchId))->toBe(0)
        ->and($account->meetings()->count())->toBe(1);

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();

    expect($notification->data['viewData']['kind'])->toBe('complete')
        ->and($notification->data['body'])->not->toContain(trans_choice(
            'filament/notifications/mailbox-import-complete.failed_calendar_events',
            2,
            ['count' => 2],
        ));
});

it('sends a recovery notice when a worker finishes during the retry request', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->once()->ordered()->andThrow(new RuntimeException('Provider unavailable'));
        $service->shouldReceive('fetchMessage')->once()->ordered()->andReturn(mailboxImportFetchedEmail('fail-1'));
    });

    $job = new StoreEmailJob($account, 'fail-1');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    workMailboxImportQueueOnce();

    expect($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');

    $workedDuringRetry = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$workedDuringRetry): void {
        if ($workedDuringRetry || ! str_contains(strtolower($query->sql), 'insert into') || ! str_contains($query->sql, 'jobs')) {
            return;
        }

        $workedDuringRetry = true;

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'emails-sync',
            '--once' => true,
            '--sleep' => 0,
            '--stop-when-empty' => true,
        ]);
    });

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeTrue()
        ->and($workedDuringRetry)->toBeTrue();

    $notifications = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->get();

    expect($account->emails()->count())->toBe(1)
        ->and($notifications)->toHaveCount(2)
        ->and($notifications->pluck('data.viewData.kind')->all())->toContain('partial', 'retry_success')
        ->and(resolve(MailboxHistoryImportService::class)->hasAwaitingRetrySuccessNotice($batchId))->toBeFalse();
});

it('does not send the import summary after the calendar progress marker expires', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    $account->refresh();
    $batchId = (string) $account->history_import_batch_id;

    $account->update(['sync_cursor' => 'history-done']);
    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 1,
        'pending_jobs' => 0,
        'failed_jobs' => 0,
        'finished_at' => now()->getTimestamp(),
    ]);

    MailboxSyncTracker::markCalendarFinished($account->fresh());
    $this->travel(31)->minutes();

    expect(MailboxSyncTracker::isCalendarSyncing($account->fresh()))->toBeFalse();

    resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $batchId);

    expect($user->notifications()->count())->toBe(0)
        ->and($account->fresh()->calendar_sync_cursor)->toBeNull();

    DB::table('jobs')->delete();
    bindMailboxImportCalendarService([mailboxImportCalendarEvent('evt-1')]);
    (new InitialCalendarSyncJob($account->fresh()))->handle(resolve(CalendarServiceFactoryInterface::class));
    workMailboxImportQueueUntilEmpty();

    expect($account->fresh()->calendar_sync_cursor)->toBe('calendar-done')
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('complete');
});

it('does not send the import summary while an expired calendar cursor rebuilds', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'stale-token',
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(
        [mailboxImportCalendarEvent('evt-1')],
        fetchDeltaException: CalendarSyncTokenExpired::forAccount($account->getKey()),
    );

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    $sawExpiredCursorHandoff = false;

    while (DB::table('jobs')->where('queue', 'emails-sync')->exists()) {
        $account->refresh();
        $initialQueued = DB::table('jobs')->where('queue', 'emails-sync')->get()->contains(function (object $job): bool {
            $payload = json_decode((string) $job->payload, true);

            return is_array($payload) && str_contains((string) ($payload['displayName'] ?? ''), class_basename(InitialCalendarSyncJob::class));
        });

        if ($account->sync_cursor !== null
            && $account->calendar_sync_cursor === null
            && $initialQueued
            && $user->notifications()->count() === 0) {
            $sawExpiredCursorHandoff = true;
            resolve(CompleteMailboxHistoryImportAction::class)->execute(
                (string) $account->getKey(),
                (string) $account->history_import_batch_id,
            );
            expect($user->notifications()->count())->toBe(0);
        }

        workMailboxImportQueueOnce();
    }

    expect($sawExpiredCursorHandoff)->toBeTrue()
        ->and($account->fresh()->calendar_sync_cursor)->toBe('calendar-done')
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('complete');
});

it('sends one recovery notice after a calendar-only failure is repaired', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(initialSyncException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty(tries: 1);

    expect($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial')
        ->and($account->fresh()->calendar_sync_cursor)->toBeNull()
        ->and($account->fresh()->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue();

    bindMailboxImportCalendarService();
    retryMailboxImportFromNotification($account->fresh());

    expect(mailboxImportQueueContains(InitialCalendarSyncJob::class))->toBeTrue()
        ->and(mailboxImportQueueContains(StoreEmailJob::class))->toBeFalse();

    workMailboxImportQueueUntilEmpty(tries: 1);

    $notifications = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->get();
    $retrySuccess = $notifications->first(
        fn ($notification): bool => ($notification->data['viewData']['kind'] ?? null) === 'retry_success',
    );

    expect($account->fresh()->calendar_sync_cursor)->toBe('calendar-done')
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeFalse()
        ->and($notifications)->toHaveCount(2)
        ->and($notifications->pluck('data.viewData.kind')->all())->toContain('partial', 'retry_success')
        ->and($retrySuccess)->not->toBeNull()
        ->and($retrySuccess->data['actions'] ?? [])->toBe([]);
});

it('reports a calendar API failure on re-import when the mailbox already has a cursor', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'calendar-token',
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(fetchDeltaException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty(tries: 1);

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();

    expect($notification->data['viewData']['kind'])->toBe('partial')
        ->and($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title_with_issues'))
        ->and($account->fresh()->calendar_sync_cursor)->toBe('calendar-token')
        ->and($account->fresh()->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()->last_error)->toBe('Calendar API unavailable')
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and(resolve(MailboxHistoryImportService::class)->calendarFailureCount((string) $account->fresh()->history_import_batch_id))->toBe(1);
});

it('retries a calendar API failure from the notification without requeueing email jobs', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'calendar-token',
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(fetchDeltaException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty(tries: 1);

    bindMailboxImportCalendarService();
    retryMailboxImportFromNotification($account->fresh());

    expect(mailboxImportQueueContains(InitialCalendarSyncJob::class))->toBeTrue()
        ->and(mailboxImportQueueContains(StoreEmailJob::class))->toBeFalse()
        ->and(mailboxImportQueueContains(IncrementalCalendarSyncJob::class))->toBeFalse();

    workMailboxImportQueueUntilEmpty(tries: 1);

    $notifications = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->get();

    expect($account->fresh()->calendar_sync_cursor)->toBe('calendar-done')
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeFalse()
        ->and($notifications)->toHaveCount(2)
        ->and($notifications->pluck('data.viewData.kind')->all())->toContain('partial', 'retry_success');
});

it('queues calendar retry when the dispatched job is not in the database jobs table', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'calendar-token',
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(fetchDeltaException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty(tries: 1);

    Queue::fake();
    retryMailboxImportFromNotification($account->fresh());

    Queue::assertPushed(InitialCalendarSyncJob::class);
    expect(DB::table('jobs')->where('queue', 'emails-sync')->count())->toBe(0)
        ->and($account->fresh()->calendar_sync_cursor)->toBeNull()
        ->and($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1)
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');
});

it('does not complete a history import retry from a concurrent incremental sync', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'calendar-token',
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(fetchDeltaException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty(tries: 1);

    bindMailboxImportCalendarService(events: [mailboxImportCalendarEvent('concurrent-delta')]);
    retryMailboxImportFromNotification($account->fresh());

    $batchId = (string) $account->fresh()->history_import_batch_id;
    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->never();

    (new IncrementalCalendarSyncJob($account->fresh()))->handle($factory);

    expect($account->fresh()->calendar_sync_cursor)->toBeNull()
        ->and(resolve(MailboxHistoryImportService::class)->isCalendarImportPending($batchId))->toBeTrue()
        ->and(mailboxImportQueueContains(InitialCalendarSyncJob::class))->toBeTrue()
        ->and($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1)
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');
});

it('retries email and calendar together then recovers only after both succeed', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'calendar-token',
    ]);

    bindMailboxImportMailService(['ok-1', 'fail-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
        $service->shouldReceive('fetchMessage')->with('fail-1')->andThrow(new RuntimeException('Provider unavailable'));
    });
    bindMailboxImportCalendarService(fetchDeltaException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty(tries: 1);

    expect($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial')
        ->and($account->emails()->count())->toBe(1)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue();

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('fail-1')->andReturn(mailboxImportFetchedEmail('fail-1'));
    });
    bindMailboxImportCalendarService(initialSyncException: new RuntimeException('Calendar API unavailable'));

    retryMailboxImportFromNotification($account->fresh());

    expect(mailboxImportQueueContains(StoreEmailJob::class))->toBeTrue()
        ->and(mailboxImportQueueContains(InitialCalendarSyncJob::class))->toBeTrue()
        ->and(mailboxImportQueueContains(IncrementalCalendarSyncJob::class))->toBeFalse();

    workMailboxImportQueueUntilEmpty(tries: 1);

    expect($account->emails()->count())->toBe(2)
        ->and($account->fresh()->calendar_sync_cursor)->toBeNull()
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1);

    bindMailboxImportCalendarService();
    retryMailboxImportFromNotification($account->fresh());

    expect(mailboxImportQueueContains(InitialCalendarSyncJob::class))->toBeTrue()
        ->and(mailboxImportQueueContains(StoreEmailJob::class))->toBeFalse()
        ->and(mailboxImportQueueContains(IncrementalCalendarSyncJob::class))->toBeFalse();

    workMailboxImportQueueUntilEmpty(tries: 1);

    $notifications = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->get();

    expect($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeFalse()
        ->and($notifications)->toHaveCount(2)
        ->and($notifications->pluck('data.viewData.kind')->all())->toContain('partial', 'retry_success');
});

it('keeps retry queued when Retry is clicked again while calendar recovery is in flight', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'calendar-token',
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(fetchDeltaException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty(tries: 1);

    bindMailboxImportCalendarService();
    retryMailboxImportFromNotification($account->fresh());

    expect(DB::table('jobs')->where('queue', 'emails-sync')->count())->toBe(1);

    retryMailboxImportFromNotification($account->fresh());

    expect(DB::table('jobs')->where('queue', 'emails-sync')->count())->toBe(1)
        ->and($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1);

    workMailboxImportQueueUntilEmpty(tries: 1);

    expect($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(2);
});

it('does not retry calendar work when the mailbox needs reconnection', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'calendar-token',
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(fetchDeltaException: new RuntimeException('invalid_grant'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty(tries: 1);

    expect($account->fresh()->status)->toBe(EmailAccountStatus::REAUTH_REQUIRED)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue();

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), (string) $account->fresh()->history_import_batch_id))->toBeFalse();

    expect(DB::table('jobs')->where('queue', 'emails-sync')->count())->toBe(0)
        ->and($account->fresh()->status)->toBe(EmailAccountStatus::REAUTH_REQUIRED)
        ->and($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1);
});

it('does not retry another users mailbox from the notification action', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $owner = mailboxImportNotificationUser();
    $account = mailboxImportNotificationAccount($owner, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->andThrow(new RuntimeException('Provider unavailable'));
    });

    $job = new StoreEmailJob($account, 'fail-1');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    workMailboxImportQueueOnce();

    expect($owner->notifications()->count())->toBe(1);

    Livewire::test(EmailAccessNotificationHandler::class)
        ->dispatch('retry-mailbox-history-import', accountId: (string) $account->getKey(), batchId: $batchId);

    expect(DB::table('jobs')->where('queue', 'emails-sync')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('keeps delayed mail counts at the snapshot taken when the import finished', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty();

    $databaseNotification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();
    $queuedMail = new MailboxHistoryImportCompletedNotification(
        $account->fresh(),
        $account->history_import_batch_id,
    );

    Email::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->user_id,
        'provider_message_id' => 'later-sync',
    ]);

    $imported = __('filament/notifications/mailbox-import-complete.imported_without_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 1, ['count' => 1]),
    ]);

    expect($account->fresh()->emails()->count())->toBe(2)
        ->and($databaseNotification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]));

    assertMailboxImportMail(
        $user,
        $queuedMail,
        __('filament/notifications/mailbox-import-complete.mail.subject'),
        __('filament/notifications/mailbox-import-complete.mail.line', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]),
    );
});
