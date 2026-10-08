<?php

declare(strict_types=1);

use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
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
