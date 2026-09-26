<?php

declare(strict_types=1);

use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Jobs\Concerns\ReleasesOnProviderRateLimit;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\ProviderRateLimit;

mutates(StoreEmailJob::class, ProviderRateLimit::class, ReleasesOnProviderRateLimit::class);

function gmailUserRateLimited(string $retryAfterIso): GoogleServiceException
{
    $message = <<<JSON
{
  "error": {
    "code": 429,
    "message": "User-rate limit exceeded.  Retry after {$retryAfterIso}",
    "errors": [
      {
        "message": "User-rate limit exceeded.  Retry after {$retryAfterIso}",
        "domain": "global",
        "reason": "rateLimitExceeded"
      }
    ],
    "status": "RESOURCE_EXHAUSTED"
  }
}
JSON;

    return new GoogleServiceException($message, 429);
}

function inboundFetchedEmail(string $providerMessageId): FetchedEmailData
{
    return new FetchedEmailData(
        providerMessageId: $providerMessageId,
        rfcMessageId: "<{$providerMessageId}@example.com>",
        threadId: "thread-{$providerMessageId}",
        inReplyTo: null,
        subject: 'Quarterly review',
        snippet: 'Notes attached',
        sentAt: now(),
        direction: EmailDirection::INBOUND,
        folder: EmailFolder::Inbox,
        hasAttachments: false,
        isRead: false,
        bodyText: 'Notes attached',
        bodyHtml: '<p>Notes attached</p>',
        participants: [
            ['email_address' => 'sender@example.com', 'name' => 'Sender', 'role' => 'from'],
        ],
        attachments: [],
    );
}

function runStoreEmailJobWithQueue(
    ConnectedAccount $account,
    string $messageId,
    MailServiceFactoryInterface $factory,
    QueueJob $queueJob,
): void {
    $job = new StoreEmailJob($account, $messageId);
    $job->setJob($queueJob);
    $job->handle($factory, resolve(StoreEmailAction::class));
}

it('uses the same retry schedule as the other email sync jobs', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    Queue::connection('database')->push(new StoreEmailJob($account, 'msg-policy'), '', 'emails-sync');

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload['maxTries'])->toBe(3)
        ->and($payload['maxExceptions'])->toBe(3)
        ->and($payload['backoff'])->toBe('60,300,900');
});

it('reads store backoff delays from email integration config', function (): void {
    config()->set('email-integration.sync.store_backoff', [10, 20]);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    Queue::connection('database')->push(new StoreEmailJob($account, 'msg-backoff'), '', 'emails-sync');

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload['backoff'])->toBe('10,20');
});

it('stores the message when a retried attempt succeeds after a failure', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_inbox' => true,
        'sync_sent' => true,
    ]));

    $failing = Mockery::mock(MailServiceInterface::class);
    $failing->shouldReceive('fetchMessage')->once()->andThrow(new GoogleServiceException('Backend Error', 500));

    $failingFactory = Mockery::mock(MailServiceFactoryInterface::class);
    $failingFactory->shouldReceive('make')->once()->andReturn($failing);

    expect(fn () => runStoreEmailJobWithQueue($account, 'msg-retry', $failingFactory, Mockery::mock(QueueJob::class)))
        ->toThrow(GoogleServiceException::class);

    $succeeding = Mockery::mock(MailServiceInterface::class);
    $succeeding->shouldReceive('fetchMessage')->once()->andReturn(inboundFetchedEmail('msg-retry'));

    $succeedingFactory = Mockery::mock(MailServiceFactoryInterface::class);
    $succeedingFactory->shouldReceive('make')->once()->andReturn($succeeding);

    runStoreEmailJobWithQueue($account, 'msg-retry', $succeedingFactory, Mockery::mock(QueueJob::class));

    expect(Email::query()->where('connected_account_id', $account->id)->count())->toBe(1);
});

it('throws on a 429 for history import batch jobs so tries and backoff apply', function (): void {
    $this->travelTo('2026-09-07 14:36:30');

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    $batch = Bus::batch([])->name('history')->allowFailures()->dispatch();

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')
        ->once()
        ->andThrow(gmailUserRateLimited('2026-09-07T14:51:30.000Z'));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->never();

    $job = (new StoreEmailJob($account, 'msg-batch-429'))->withBatchId($batch->id);
    $job->setJob($queueJob);

    $job->handle($factory, resolve(StoreEmailAction::class));
})->throws(GoogleServiceException::class);

it('releases until Google retry-after instead of failing a 429', function (): void {
    $this->travelTo('2026-09-07 14:36:30');

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')
        ->once()
        ->andThrow(gmailUserRateLimited('2026-09-07T14:51:30.000Z'));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(900);

    runStoreEmailJobWithQueue($account, 'msg-429', $factory, $queueJob);

    expect(Email::query()->where('connected_account_id', $account->id)->count())->toBe(0);
});

it('does not call the mailbox for other messages while that account is cooling down', function (): void {
    $this->travelTo('2026-09-07 14:36:30');

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')
        ->once()
        ->with('msg-first')
        ->andThrow(gmailUserRateLimited('2026-09-07T14:51:30.000Z'));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    $firstQueue = Mockery::mock(QueueJob::class);
    $firstQueue->shouldReceive('release')->once()->with(900);

    runStoreEmailJobWithQueue($account, 'msg-first', $factory, $firstQueue);

    $quietFactory = Mockery::mock(MailServiceFactoryInterface::class);
    $quietFactory->shouldReceive('make')->never();

    $secondQueue = Mockery::mock(QueueJob::class);
    $secondQueue->shouldReceive('release')->once()->with(900);

    runStoreEmailJobWithQueue($account, 'msg-second', $quietFactory, $secondQueue);
});

it('skips storing when the provider reports the message as gone', function (GoogleServiceException|RequestException $exception): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')
        ->once()
        ->andThrow($exception);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->never();

    runStoreEmailJobWithQueue($account, 'msg-gone', $factory, $queueJob);

    expect(Email::query()->where('connected_account_id', $account->id)->count())->toBe(0);
})->with([
    'Gmail 404' => fn (): GoogleServiceException => new GoogleServiceException('Requested entity was not found.', 404),
    'Microsoft Graph 404' => fn (): RequestException => new RequestException(new Response(new Psr7Response(404, [], '{}'))),
]);

it('still fails when the provider error is not a rate limit', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')
        ->once()
        ->andThrow(new GoogleServiceException('Backend Error', 500));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->never();

    $job = new StoreEmailJob($account, 'msg-500');
    $job->setJob($queueJob);

    $job->handle($factory, resolve(StoreEmailAction::class));
})->throws(GoogleServiceException::class, 'Backend Error');

it('adopts a pending Microsoft sent row when the canonical Graph id arrives', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_inbox' => true,
        'sync_sent' => true,
    ]));

    $sent = Email::factory()->outbound()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->user_id,
        'connected_account_id' => $account->getKey(),
        'rfc_message_id' => '<local-id@example.com>',
        'provider_message_id' => 'ms-pending-01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'thread_id' => 'ms-pending-thread-01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'status' => EmailStatus::SENT,
        'subject' => 'Hi',
    ]);

    $fetched = new FetchedEmailData(
        providerMessageId: 'AAA1',
        rfcMessageId: '<provider-id@example.com>',
        threadId: 'conversation-1',
        inReplyTo: null,
        subject: 'Hi from Graph',
        snippet: 'Hi',
        sentAt: now(),
        direction: EmailDirection::OUTBOUND,
        folder: EmailFolder::Sent,
        hasAttachments: false,
        isRead: true,
        bodyText: 'Hi',
        bodyHtml: '<p>Hi</p>',
        participants: [
            ['email_address' => 'owner@example.com', 'name' => 'Owner', 'role' => 'from'],
        ],
        attachments: [],
        reconciliationMessageId: '<local-id@example.com>',
    );

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')->once()->with('AAA1')->andReturn($fetched);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    $job = new StoreEmailJob($account, 'AAA1');
    $job->handle($factory, resolve(StoreEmailAction::class));

    expect(Email::query()->where('connected_account_id', $account->id)->count())->toBe(1)
        ->and($sent->refresh()->provider_message_id)->toBe('AAA1')
        ->and($sent->thread_id)->toBe('conversation-1')
        ->and($sent->rfc_message_id)->toBe('<provider-id@example.com>')
        ->and($sent->status)->toBe(EmailStatus::SENT);
});

it('releases using the Retry-After header from Microsoft Graph', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $exception = new RequestException(new Response(new Psr7Response(429, ['Retry-After' => '120'], '{}')));

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')->once()->andThrow($exception);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(120);

    runStoreEmailJobWithQueue($account, 'msg-graph', $factory, $queueJob);
});
