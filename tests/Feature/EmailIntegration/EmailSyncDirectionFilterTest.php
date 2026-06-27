<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;

mutates(StoreEmailJob::class);

/**
 * Build a StoreEmailJob whose mailbox returns a message of the given direction,
 * then run it against the account's inbox/sent toggles.
 */
function runStoreEmailJob(ConnectedAccount $account, EmailDirection $direction): void
{
    $fetched = new FetchedEmailData(
        providerMessageId: 'msg-'.$direction->value,
        rfcMessageId: '<msg@example.com>',
        threadId: 'thread-1',
        inReplyTo: null,
        subject: 'Hello',
        snippet: 'Hello',
        sentAt: Carbon::now(),
        direction: $direction,
        folder: $direction === EmailDirection::OUTBOUND ? EmailFolder::Sent : EmailFolder::Inbox,
        hasAttachments: false,
        isRead: true,
        bodyText: 'Hello',
        bodyHtml: '<p>Hello</p>',
        participants: [
            ['email_address' => 'someone@example.com', 'name' => 'Someone', 'role' => 'from'],
        ],
        attachments: [],
    );

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')->andReturn($fetched);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new StoreEmailJob($account, $fetched->providerMessageId))->handle($factory, resolve(StoreEmailAction::class));
}

it('skips storing a sent email when sync_sent is off', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create(['sync_sent' => false, 'sync_inbox' => true]));

    runStoreEmailJob($account, EmailDirection::OUTBOUND);

    expect(Email::query()->where('connected_account_id', $account->id)->count())->toBe(0);
});

it('skips storing an inbox email when sync_inbox is off', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create(['sync_inbox' => false, 'sync_sent' => true]));

    runStoreEmailJob($account, EmailDirection::INBOUND);

    expect(Email::query()->where('connected_account_id', $account->id)->count())->toBe(0);
});

it('stores an email whose direction is enabled', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create(['sync_inbox' => true, 'sync_sent' => true]));

    runStoreEmailJob($account, EmailDirection::INBOUND);

    expect(Email::query()->where('connected_account_id', $account->id)->count())->toBe(1);
});
