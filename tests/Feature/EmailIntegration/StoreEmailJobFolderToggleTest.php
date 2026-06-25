<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
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

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

/**
 * @param  array<string, mixed>  $accountAttributes
 */
function toggleAccount(array $accountAttributes): ConnectedAccount
{
    /** @var User $user */
    $user = test()->user;

    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => test()->team->getKey(),
        'user_id' => $user->getKey(),
        ...$accountAttributes,
    ]));
}

function runStoreEmailJob(ConnectedAccount $account, EmailFolder $folder): void
{
    $fetched = new FetchedEmailData(
        providerMessageId: 'msg-toggle',
        rfcMessageId: '<msg-toggle@example.com>',
        threadId: 'thread-toggle',
        inReplyTo: null,
        subject: 'Toggle test',
        snippet: 'snippet',
        sentAt: now(),
        direction: $folder === EmailFolder::Sent ? EmailDirection::OUTBOUND : EmailDirection::INBOUND,
        folder: $folder,
        hasAttachments: false,
        isRead: false,
        bodyText: 'body',
        bodyHtml: '<p>body</p>',
        participants: [
            ['email_address' => 'sender@external.com', 'name' => 'Sender', 'role' => 'from'],
        ],
        attachments: [],
    );

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')->andReturn($fetched);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    (new StoreEmailJob($account, 'msg-toggle'))->handle($factory, resolve(StoreEmailAction::class));
}

it('skips storing an inbox message when sync_inbox is off', function (): void {
    $account = toggleAccount(['sync_inbox' => false, 'sync_sent' => true]);

    runStoreEmailJob($account, EmailFolder::Inbox);

    expect(Email::query()->where('connected_account_id', $account->getKey())->exists())->toBeFalse();
});

it('stores an inbox message when sync_inbox is on', function (): void {
    $account = toggleAccount(['sync_inbox' => true, 'sync_sent' => false]);

    runStoreEmailJob($account, EmailFolder::Inbox);

    expect(Email::query()->where('connected_account_id', $account->getKey())->exists())->toBeTrue();
});

it('skips storing a sent message when sync_sent is off', function (): void {
    $account = toggleAccount(['sync_inbox' => true, 'sync_sent' => false]);

    runStoreEmailJob($account, EmailFolder::Sent);

    expect(Email::query()->where('connected_account_id', $account->getKey())->exists())->toBeFalse();
});

it('stores a sent message when sync_sent is on', function (): void {
    $account = toggleAccount(['sync_inbox' => false, 'sync_sent' => true]);

    runStoreEmailJob($account, EmailFolder::Sent);

    expect(Email::query()->where('connected_account_id', $account->getKey())->exists())->toBeTrue();
});

it('stores non inbox/sent folders regardless of toggles', function (): void {
    $account = toggleAccount(['sync_inbox' => false, 'sync_sent' => false]);

    runStoreEmailJob($account, EmailFolder::Archive);

    expect(Email::query()->where('connected_account_id', $account->getKey())->exists())->toBeTrue();
});
