<?php

declare(strict_types=1);

use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\SyncErrorReason;
use Relaticle\EmailIntegration\Jobs\EnsureCalendarPushChannelJob;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\Middleware\HandlesProviderFailures;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Services\ProviderRateLimit;

mutates(HandlesProviderFailures::class, ProviderRateLimit::class);

function mailboxWithCursors(): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'status' => EmailAccountStatus::ACTIVE,
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => 'mail-cursor',
        'calendar_sync_cursor' => 'calendar-cursor',
    ]));
}

function providerThrows(Throwable $exception): void
{
    $mail = Mockery::mock(MailServiceInterface::class);
    $mail->shouldReceive('fetchDelta', 'initialBackfill')->andThrow($exception);
    $mailFactory = Mockery::mock(MailServiceFactoryInterface::class);
    $mailFactory->shouldReceive('make')->andReturn($mail);
    app()->instance(MailServiceFactoryInterface::class, $mailFactory);

    $calendar = Mockery::mock(CalendarServiceInterface::class);
    $calendar->shouldReceive('fetchDelta', 'initialSync', 'ensurePushChannel')->andThrow($exception);
    $calendarFactory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $calendarFactory->shouldReceive('make')->andReturn($calendar);
    app()->instance(CalendarServiceFactoryInterface::class, $calendarFactory);
}

function graphBadGateway(): RequestException
{
    return new RequestException(new Response(new PsrResponse(502, [], '{"error":{"code":"UnknownError","message":""}}')));
}

function googleInvalidCredentials(): GoogleServiceException
{
    return new GoogleServiceException('{"error":{"code":401,"message":"Request had invalid authentication credentials.","status":"UNAUTHENTICATED"}}', 401);
}

dataset('delta sync jobs', [
    'email' => [fn (ConnectedAccount $account): IncrementalEmailSyncJob => new IncrementalEmailSyncJob($account)],
    'calendar' => [fn (ConnectedAccount $account): IncrementalCalendarSyncJob => new IncrementalCalendarSyncJob($account)],
]);

dataset('provider-calling jobs', [
    'incremental email' => [fn (ConnectedAccount $account): IncrementalEmailSyncJob => new IncrementalEmailSyncJob($account)],
    'initial email' => [fn (ConnectedAccount $account): InitialEmailSyncJob => new InitialEmailSyncJob($account)],
    'incremental calendar' => [fn (ConnectedAccount $account): IncrementalCalendarSyncJob => new IncrementalCalendarSyncJob($account)],
    'initial calendar' => [fn (ConnectedAccount $account): InitialCalendarSyncJob => new InitialCalendarSyncJob($account)],
    'calendar push channel' => [fn (ConnectedAccount $account): EnsureCalendarPushChannelJob => new EnsureCalendarPushChannelJob($account)],
]);

function workMailboxJob(?object $job = null): void
{
    if ($job !== null) {
        Queue::connection('database')->push($job, '', 'provider-failures');
    }

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'provider-failures', '--once' => true]);
}

it('parks the job and keeps the mailbox active when the provider answers 502', function (Closure $job): void {
    config()->set('app.url', 'https://app.relaticle.com');
    $account = mailboxWithCursors();
    providerThrows(graphBadGateway());

    workMailboxJob($job($account));

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and(ProviderRateLimit::remainingSeconds((string) $account->getKey()))->toBeGreaterThan(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Exceptions::assertNothingReported();
})->with('provider-calling jobs');

it('parks the job and keeps the mailbox active when the provider cannot be reached', function (Closure $job, Throwable $failure): void {
    config()->set('app.url', 'https://app.relaticle.com');
    $account = mailboxWithCursors();
    providerThrows($failure);

    workMailboxJob($job($account));

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and(ProviderRateLimit::remainingSeconds((string) $account->getKey()))->toBeBetween(55, 60)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Exceptions::assertNothingReported();
})->with('provider-calling jobs')->with([
    'graph' => [new ConnectionException('cURL error 28: Resolving timed out after 10001 milliseconds for https://graph.microsoft.com/v1.0/me/mailFolders/inbox')],
    'google' => [new ConnectException('cURL error 28: Resolving timed out after 10001 milliseconds', new PsrRequest('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/history'))],
]);

it('gives a rejected token one more attempt, then asks for a reconnect without reporting', function (Closure $job): void {
    $account = mailboxWithCursors();
    providerThrows(googleInvalidCredentials());

    workMailboxJob($job($account));

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->travel(2)->minutes();
    workMailboxJob();

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::REAUTH_REQUIRED)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    Exceptions::assertNothingReported();
})->with('delta sync jobs');

it('still reports a failure that is neither an outage nor a rejected token', function (Closure $job): void {
    $account = mailboxWithCursors();
    providerThrows(new RuntimeException('unexpected payload shape'));

    workMailboxJob($job($account));

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'unexpected payload shape');
})->with('delta sync jobs');

it('fails loudly on a sync queue, where a parked job would never run again', function (Closure $job): void {
    $account = mailboxWithCursors();
    providerThrows(graphBadGateway());

    expect(fn () => dispatch_sync($job($account)))->toThrow(RequestException::class);
})->with('delta sync jobs');

it('retries a parked mailbox job for a day instead of three attempts', function (string $job): void {
    $this->travelTo('2026-10-08 09:00:00');
    $account = mailboxWithCursors();

    Queue::connection('database')->push(new $job($account));

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload['maxTries'])->toBeNull()
        ->and($payload['maxExceptions'])->toBe(3)
        ->and($payload['retryUntil'])->toBe(now()->addDay()->getTimestamp());
})->with([
    IncrementalEmailSyncJob::class,
    InitialEmailSyncJob::class,
    IncrementalCalendarSyncJob::class,
    InitialCalendarSyncJob::class,
    EnsureCalendarPushChannelJob::class,
]);

it('parks the mailbox for as long as the provider asks when an outage names a retry time', function (): void {
    $account = mailboxWithCursors();
    providerThrows(new RequestException(new Response(new PsrResponse(503, ['Retry-After' => '120'], ''))));

    workMailboxJob(new IncrementalEmailSyncJob($account));

    expect(ProviderRateLimit::remainingSeconds((string) $account->getKey()))->toBeBetween(115, 120);
});

it('reports a client error and leaves the mailbox unparked', function (): void {
    $account = mailboxWithCursors();
    providerThrows(new RequestException(new Response(new PsrResponse(404, [], ''))));

    workMailboxJob(new IncrementalEmailSyncJob($account));

    expect(ProviderRateLimit::remainingSeconds((string) $account->getKey()))->toBeNull();

    Exceptions::assertReported(RequestException::class);
});

function googleMailServiceNotEnabled(): GoogleServiceException
{
    return new GoogleServiceException(
        '{"error":{"code":400,"message":"Mail service not enabled","errors":[{"message":"Mail service not enabled","domain":"global","reason":"failedPrecondition"}],"status":"FAILED_PRECONDITION"}}',
        400,
        null,
        [['message' => 'Mail service not enabled', 'domain' => 'global', 'reason' => 'failedPrecondition']],
    );
}

function googleNotACalendarUser(): GoogleServiceException
{
    return new GoogleServiceException(
        '{"error":{"errors":[{"domain":"calendar","reason":"notACalendarUser","message":"The user must be signed up for Google Calendar."}],"code":403}}',
        403,
        null,
        [['domain' => 'calendar', 'reason' => 'notACalendarUser', 'message' => 'The user must be signed up for Google Calendar.']],
    );
}

function googleRateLimited(): GoogleServiceException
{
    return new GoogleServiceException(
        '{"error":{"code":403,"message":"Rate Limit Exceeded","errors":[{"message":"Rate Limit Exceeded","domain":"usageLimits","reason":"rateLimitExceeded"}]}}',
        403,
        null,
        [['message' => 'Rate Limit Exceeded', 'domain' => 'usageLimits', 'reason' => 'rateLimitExceeded']],
    );
}

it('fails the mailbox with a plain reason when the workspace has switched the mail service off', function (Closure $job): void {
    $account = mailboxWithCursors();
    providerThrows(googleMailServiceNotEnabled());

    workMailboxJob($job($account));

    Exceptions::assertNothingReported();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($account->fresh()?->status)->toBe(EmailAccountStatus::ERROR)
        ->and($account->fresh()?->last_error)->toBe(SyncErrorReason::GoogleMailDisabled->value)
        ->and($account->fresh()?->syncErrorReason())->toBe("Gmail isn't enabled for this Google Workspace account. Ask your Google admin to turn it on, then reconnect.");
})->with([
    'incremental email' => [fn (ConnectedAccount $account): IncrementalEmailSyncJob => new IncrementalEmailSyncJob($account)],
    'initial email' => [fn (ConnectedAccount $account): InitialEmailSyncJob => new InitialEmailSyncJob($account)],
]);

it('turns calendar sync off and keeps the mailbox active when the user has no Google Calendar', function (Closure $job): void {
    config()->set('app.url', 'https://app.relaticle.com');
    $account = mailboxWithCursors();
    $account->update(['calendar_push_channel_id' => 'channel-1', 'calendar_push_resource_id' => 'resource-1']);
    providerThrows(googleNotACalendarUser());

    workMailboxJob($job($account));

    Exceptions::assertNothingReported();

    $fresh = $account->fresh();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($fresh?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($fresh?->hasCalendar())->toBeFalse()
        ->and($fresh?->hasEmail())->toBeTrue()
        ->and($fresh?->calendar_push_channel_id)->toBeNull()
        ->and($fresh?->last_error)->toBeNull();
})->with([
    'incremental calendar' => [fn (ConnectedAccount $account): IncrementalCalendarSyncJob => new IncrementalCalendarSyncJob($account)],
    'initial calendar' => [fn (ConnectedAccount $account): InitialCalendarSyncJob => new InitialCalendarSyncJob($account)],
    'calendar push channel' => [fn (ConnectedAccount $account): EnsureCalendarPushChannelJob => new EnsureCalendarPushChannelJob($account)],
]);

it('keeps reporting a google 403 that is not a disabled service', function (): void {
    $account = mailboxWithCursors();
    providerThrows(googleRateLimited());

    workMailboxJob(new IncrementalCalendarSyncJob($account));

    $fresh = $account->fresh();

    Exceptions::assertReported(GoogleServiceException::class);

    expect($fresh?->hasCalendar())->toBeTrue()
        ->and($fresh?->status)->not->toBe(EmailAccountStatus::REAUTH_REQUIRED);
});

it('keeps the plain mail-disabled reason when the account already carried it', function (): void {
    $account = mailboxWithCursors();
    $account->update(['last_error' => SyncErrorReason::GoogleMailDisabled->value]);
    providerThrows(googleMailServiceNotEnabled());

    workMailboxJob(new IncrementalEmailSyncJob($account->fresh()));

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ERROR)
        ->and($account->fresh()?->last_error)->toBe(SyncErrorReason::GoogleMailDisabled->value);
});

it('settles a pending calendar history import when the user has no Google Calendar', function (): void {
    $account = mailboxWithCursors();
    $import = resolve(MailboxHistoryImportService::class);
    $batch = $import->startBatch($account);
    $account->update(['history_import_batch_id' => $batch->id]);
    $import->markCalendarImportPending($batch->id);
    providerThrows(googleNotACalendarUser());

    workMailboxJob(new InitialCalendarSyncJob($account->fresh()));

    $fresh = $account->fresh();

    expect($import->isCalendarImportPending($batch->id))->toBeFalse()
        ->and($fresh?->history_import_notified_batch_id)->toBe($batch->id)
        ->and($fresh?->hasCalendar())->toBeFalse();
});

it('retries a token refresh that Google answers with internal_failure instead of reporting it', function (): void {
    $account = mailboxWithCursors();

    $mailFactory = Mockery::mock(MailServiceFactoryInterface::class);
    $mailFactory->shouldReceive('make')->andThrow(new RuntimeException('internal_failure: token refresh failed'));
    app()->instance(MailServiceFactoryInterface::class, $mailFactory);

    workMailboxJob(new IncrementalEmailSyncJob($account));

    Exceptions::assertNothingReported();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE);
});
