<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphServiceFactory;
use Relaticle\EmailIntegration\Services\MicrosoftGraphMailService;

mutates(MicrosoftGraphMailService::class);
mutates(MicrosoftGraphServiceFactory::class);

beforeEach(function (): void {
    config()->set('services.azure.client_id', 'azure-client-id');
    config()->set('services.azure.client_secret', 'azure-client-secret');
    config()->set('services.azure.tenant', 'common');

    // Prevent the ConnectedAccountObserver from running InitialEmailSyncJob synchronously
    // during account creation, which would issue unfaked Graph requests.
    Bus::fake();
});

function makeAzureAccount(): ConnectedAccount
{
    $user = User::factory()->withTeam()->create();

    return ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'team_id' => $user->currentTeam->getKey(),
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
        ]);
}

it('returns initial backfill cursor as the Graph deltaLink', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta*' => Http::response([
            'value' => [
                ['id' => 'AAA1', 'isRead' => false],
                ['id' => 'AAA2', 'isRead' => true],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta?$deltatoken=TKN',
        ]),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $result = $service->initialBackfill(90);

    expect($result['cursor'])->toContain('$deltatoken=TKN')
        ->and($result['message_ids']->all())->toEqual(['AAA1', 'AAA2']);
});

it('paginates delta with @odata.nextLink and surfaces new + read ids + new cursor', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta?$deltatoken=OLD' => Http::response([
            'value' => [
                ['id' => 'AAA1', 'isRead' => false],
            ],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta?$skiptoken=NEXT',
        ]),
        'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta?$skiptoken=NEXT' => Http::response([
            'value' => [
                ['id' => 'AAA2', 'isRead' => true],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta?$deltatoken=FRESH',
        ]),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $delta = $service->fetchDelta('https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta?$deltatoken=OLD');

    expect($delta->messageIds->all())->toEqual(['AAA1', 'AAA2'])
        ->and($delta->readMessageIds->all())->toEqual(['AAA2'])
        ->and($delta->newCursor)->toContain('$deltatoken=FRESH');
});

it('maps a Graph message payload to FetchedEmailData', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders*' => Http::response([
            'value' => [['id' => 'inbox-folder-id', 'displayName' => 'Inbox']],
        ]),
        'https://graph.microsoft.com/v1.0/me/messages/AAA1*' => Http::response([
            'id' => 'AAA1',
            'internetMessageId' => '<rfc-abc@example.com>',
            'conversationId' => 'thread-1',
            'subject' => 'Hello',
            'bodyPreview' => 'Hi there',
            'receivedDateTime' => '2026-01-15T10:00:00Z',
            'isRead' => false,
            'hasAttachments' => false,
            'parentFolderId' => 'inbox-folder-id',
            'from' => ['emailAddress' => ['address' => 'sender@example.com', 'name' => 'Sender']],
            'toRecipients' => [['emailAddress' => ['address' => 'a@example.com', 'name' => 'Me']]],
            'ccRecipients' => [],
            'bccRecipients' => [],
            'body' => ['contentType' => 'html', 'content' => '<p>Hi</p>'],
        ]),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $email = $service->fetchMessage('AAA1');

    expect($email->providerMessageId)->toBe('AAA1')
        ->and($email->rfcMessageId)->toBe('<rfc-abc@example.com>')
        ->and($email->threadId)->toBe('thread-1')
        ->and($email->subject)->toBe('Hello')
        ->and($email->bodyHtml)->toBe('<p>Hi</p>')
        ->and($email->direction)->toBe(EmailDirection::INBOUND)
        ->and($email->folder)->toBe(EmailFolder::Inbox)
        ->and($email->isRead)->toBeFalse();
});

it('POSTs to /me/sendMail and returns provider ids', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $result = $service->sendMessage([
        'subject' => 'Hi',
        'body_html' => '<p>Hi</p>',
        'body_text' => 'Hi',
        'to' => [['email' => 'b@example.com', 'name' => 'B']],
    ]);

    expect($result['provider_message_id'])->not->toBeEmpty();

    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/sendMail'));
});
