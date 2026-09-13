<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
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

/**
 * @param  array<string, array{id: string, displayName?: string}>  $overrides
 * @return array<string, mixed>
 */
function graphWellKnownFolderFakes(array $overrides = []): array
{
    $folders = [
        'inbox' => ['id' => 'inbox-folder-id', 'displayName' => 'Inbox'],
        'drafts' => ['id' => 'drafts-folder-id', 'displayName' => 'Drafts'],
        'sentitems' => ['id' => 'sent-folder-id', 'displayName' => 'Sent Items'],
        ...$overrides,
    ];

    $fakes = [];

    foreach ($folders as $wellKnownName => $folder) {
        $fakes["https://graph.microsoft.com/v1.0/me/mailFolders/{$wellKnownName}*"] = Http::response($folder);
    }

    return $fakes;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function graphMessagePayload(array $overrides = []): array
{
    return [
        'id' => 'MSG1',
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
        ...$overrides,
    ];
}

it('backfills from the all-folder /me/messages/delta stream and returns the Graph deltaLink', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/messages/delta*' => Http::response([
            'value' => [
                ['id' => 'AAA1', 'isRead' => false],
                ['id' => 'AAA2', 'isRead' => true],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=TKN',
        ]),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $result = $service->initialBackfill();

    expect($result->cursor)->toContain('$deltatoken=TKN')
        ->and($result->messageIds->all())->toEqual(['AAA1', 'AAA2'])
        ->and($result->nextPageToken)->toBeNull();

    // Must hit the all-folder endpoint, not the Inbox-only one, so Sent mail syncs.
    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/messages/delta')
        && ! str_contains((string) $r->url(), 'mailFolders')
        && ! str_contains((string) $r->url(), 'receivedDateTime'));
});

it('returns one backfill page and does not follow @odata.nextLink', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/messages/delta' => Http::response([
            'value' => [
                ['id' => 'AAA1'],
            ],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/messages/delta?$skiptoken=NEXT',
        ]),
        'https://graph.microsoft.com/v1.0/me/messages/delta?$skiptoken=NEXT' => Http::response([
            'value' => [
                ['id' => 'AAA2'],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=TKN',
        ]),
    ]);

    $result = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->initialBackfill();

    expect($result->messageIds->all())->toEqual(['AAA1'])
        ->and($result->nextPageToken)->toContain('$skiptoken=NEXT')
        ->and($result->cursor)->toBeNull();

    Http::assertSentCount(1);
});

it('applies the optional initial_days cap as a receivedDateTime filter', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/messages/delta*' => Http::response([
            'value' => [],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=TKN',
        ]),
    ]);

    resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->initialBackfill(90);

    Http::assertSent(fn (Request $r): bool => str_contains(urldecode((string) $r->url()), 'receivedDateTime ge'));
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

it('maps a Graph drafts-folder message as an inbound draft', function (): void {
    Http::fake([
        ...graphWellKnownFolderFakes(),
        'https://graph.microsoft.com/v1.0/me/messages/DRAFT1*' => Http::response(graphMessagePayload([
            'id' => 'DRAFT1',
            'internetMessageId' => '<draft@example.com>',
            'conversationId' => 'thread-draft',
            'subject' => 'Unsent',
            'bodyPreview' => 'Still writing',
            'isRead' => true,
            'parentFolderId' => 'drafts-folder-id',
            'from' => ['emailAddress' => ['address' => 'owner@example.com', 'name' => 'Owner']],
            'toRecipients' => [['emailAddress' => ['address' => 'prospect@example.com', 'name' => 'Prospect']]],
            'body' => ['contentType' => 'html', 'content' => '<p>Still writing</p>'],
        ])),
    ]);

    $email = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->fetchMessage('DRAFT1');

    expect($email->direction)->toBe(EmailDirection::INBOUND)
        ->and($email->folder)->toBe(EmailFolder::Drafts);
});

it('maps a localized Graph drafts folder as drafts so unsent mail is excluded', function (): void {
    Http::fake([
        ...graphWellKnownFolderFakes([
            'drafts' => ['id' => 'drafts-folder-id', 'displayName' => 'Entwürfe'],
            'inbox' => ['id' => 'inbox-folder-id', 'displayName' => 'Posteingang'],
            'sentitems' => ['id' => 'sent-folder-id', 'displayName' => 'Gesendete Elemente'],
        ]),
        'https://graph.microsoft.com/v1.0/me/messages/DRAFT1*' => Http::response(graphMessagePayload([
            'id' => 'DRAFT1',
            'internetMessageId' => '<draft@example.com>',
            'conversationId' => 'thread-draft',
            'subject' => 'Unsent pitch',
            'bodyPreview' => 'Still writing',
            'isRead' => true,
            'parentFolderId' => 'drafts-folder-id',
            'from' => ['emailAddress' => ['address' => 'owner@example.com', 'name' => 'Owner']],
            'toRecipients' => [['emailAddress' => ['address' => 'prospect@example.com', 'name' => 'Prospect']]],
            'body' => ['contentType' => 'html', 'content' => '<p>Still writing</p>'],
        ])),
    ]);

    $account = makeAzureAccount();

    $email = resolve(MicrosoftGraphServiceFactory::class)->make($account)->fetchMessage('DRAFT1');

    expect($email->folder)->toBe(EmailFolder::Drafts)
        ->and($email->direction)->toBe(EmailDirection::INBOUND);

    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/mailFolders/drafts'));

    (new StoreEmailJob($account, 'DRAFT1'))->handle(
        resolve(MailServiceFactoryInterface::class),
        resolve(StoreEmailAction::class),
    );

    expect(Email::query()->where('connected_account_id', $account->id)->count())->toBe(0);
});

it('maps a localized Graph sent items folder as sent', function (): void {
    Http::fake([
        ...graphWellKnownFolderFakes([
            'sentitems' => ['id' => 'sent-folder-id', 'displayName' => 'Gesendete Elemente'],
        ]),
        'https://graph.microsoft.com/v1.0/me/messages/SENT1*' => Http::response(graphMessagePayload([
            'id' => 'SENT1',
            'parentFolderId' => 'sent-folder-id',
        ])),
    ]);

    $email = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->fetchMessage('SENT1');

    expect($email->folder)->toBe(EmailFolder::Sent)
        ->and($email->direction)->toBe(EmailDirection::OUTBOUND);
});

it('does not treat a custom folder named Drafts as the well-known drafts folder', function (): void {
    Http::fake([
        ...graphWellKnownFolderFakes(),
        'https://graph.microsoft.com/v1.0/me/messages/CUSTOM1*' => Http::response(graphMessagePayload([
            'id' => 'CUSTOM1',
            'parentFolderId' => 'custom-drafts-id',
        ])),
    ]);

    $email = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->fetchMessage('CUSTOM1');

    expect($email->folder)->toBe(EmailFolder::Archive)
        ->and($email->direction)->toBe(EmailDirection::INBOUND);
});

it('maps a Graph message payload to FetchedEmailData', function (): void {
    Http::fake([
        ...graphWellKnownFolderFakes(),
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
        'rfc_message_id' => '<local-id@example.com>',
    ]);

    expect($result['provider_message_id'])->not->toBeEmpty();

    Http::assertSent(function (Request $r): bool {
        $message = $r->data()['message'];

        return str_contains((string) $r->url(), '/me/sendMail')
            && ! array_key_exists('internetMessageId', $message)
            && $message['singleValueExtendedProperties'] === [[
                'id' => 'String {00020329-0000-0000-C000-000000000046} Name RelaticleMessageId',
                'value' => '<local-id@example.com>',
            ]];
    });
});

it('finds a sent message by its reconciliation property', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/messages*' => Http::response([
            'value' => [[
                'id' => 'AAA1',
                'conversationId' => 'thread-1',
                'internetMessageId' => '<provider-id@example.com>',
            ]],
        ]),
    ]);

    $result = resolve(MicrosoftGraphServiceFactory::class)
        ->make(makeAzureAccount())
        ->findSentMessage('<local-id@example.com>');

    expect($result)->toBe([
        'provider_message_id' => 'AAA1',
        'thread_id' => 'thread-1',
        'rfc_message_id' => '<provider-id@example.com>',
    ]);

    Http::assertSent(fn (Request $r): bool => str_contains(urldecode((string) $r->url()), "singleValueExtendedProperties/Any(ep: ep/id eq 'String {00020329-0000-0000-C000-000000000046} Name RelaticleMessageId' and ep/value eq '<local-id@example.com>')"));
});

it('includes file attachments in the /me/sendMail payload', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $service->sendMessage([
        'subject' => 'Hi',
        'body_html' => '<p>Hi</p>',
        'to' => [['email' => 'b@example.com', 'name' => 'B']],
        'attachments' => [[
            'filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'content' => 'PDF-BYTES',
        ]],
    ]);

    Http::assertSent(function (Request $r): bool {
        $attachments = $r->data()['message']['attachments'] ?? [];

        return $attachments !== []
            && $attachments[0]['@odata.type'] === '#microsoft.graph.fileAttachment'
            && $attachments[0]['name'] === 'report.pdf'
            && $attachments[0]['contentType'] === 'application/pdf'
            && $attachments[0]['contentBytes'] === base64_encode('PDF-BYTES');
    });
});

it('marks cid images as inline file attachments on sendMail', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $service->sendMessage([
        'subject' => 'Hi',
        'body_html' => '<p><img src="cid:logo@example.test"></p>',
        'to' => [['email' => 'b@example.com', 'name' => 'B']],
        'attachments' => [[
            'filename' => 'logo.png',
            'mime_type' => 'image/png',
            'content' => 'PNG-BYTES',
            'is_inline' => true,
            'content_id' => 'logo@example.test',
        ]],
    ]);

    Http::assertSent(function (Request $r): bool {
        $attachments = $r->data()['message']['attachments'] ?? [];

        return $attachments !== []
            && $attachments[0]['isInline'] === true
            && $attachments[0]['contentId'] === 'logo@example.test'
            && $attachments[0]['name'] === 'logo.png'
            && $attachments[0]['contentBytes'] === base64_encode('PNG-BYTES');
    });
});

it('expands and maps inbound attachment metadata into FetchedEmailData', function (): void {
    Http::fake([
        ...graphWellKnownFolderFakes(),
        'https://graph.microsoft.com/v1.0/me/messages/AAA2*' => Http::response([
            'id' => 'AAA2',
            'internetMessageId' => '<rfc-att@example.com>',
            'conversationId' => 'thread-2',
            'subject' => 'With file',
            'bodyPreview' => 'see attached',
            'receivedDateTime' => '2026-01-15T10:00:00Z',
            'isRead' => true,
            'hasAttachments' => true,
            'parentFolderId' => 'inbox-folder-id',
            'from' => ['emailAddress' => ['address' => 'sender@example.com', 'name' => 'Sender']],
            'toRecipients' => [['emailAddress' => ['address' => 'a@example.com', 'name' => 'Me']]],
            'ccRecipients' => [],
            'bccRecipients' => [],
            'body' => ['contentType' => 'html', 'content' => '<p>Hi</p>'],
            'attachments' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id' => 'att-1',
                'name' => 'report.pdf',
                'contentType' => 'application/pdf',
                'size' => 2048,
                'isInline' => false,
                'contentId' => null,
            ]],
        ]),
    ]);

    $email = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->fetchMessage('AAA2');

    expect($email->hasAttachments)->toBeTrue()
        ->and($email->attachments)->toHaveCount(1)
        ->and($email->attachments[0]['filename'])->toBe('report.pdf')
        ->and($email->attachments[0]['mime_type'])->toBe('application/pdf')
        ->and($email->attachments[0]['size'])->toBe(2048)
        ->and($email->attachments[0]['attachment_id'])->toBe('att-1')
        ->and($email->attachments[0]['inline_data'])->toBeNull();

    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/messages/AAA2')
        && str_contains(urldecode((string) $r->url()), '$expand=attachments'));
});
