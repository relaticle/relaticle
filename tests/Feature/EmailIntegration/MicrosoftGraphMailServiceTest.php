<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Exceptions\MailHistoryExpired;
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

/**
 * @param  array<string, string>  $cursors
 */
function microsoftMailCursor(array $cursors = []): string
{
    return json_encode([
        'v' => 1,
        'cursors' => [
            'inbox' => $cursors['inbox'] ?? 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=INBOX',
            'sentitems' => $cursors['sentitems'] ?? 'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta?$deltatoken=SENT',
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

it('backfills Inbox then SentItems and returns per-folder delta cursors', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta' => Http::response([
            'value' => [
                ['id' => 'IN1', 'isRead' => false],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=INBOX',
        ]),
        'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta' => Http::response([
            'value' => [
                ['id' => 'SE1', 'isRead' => true],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta?$deltatoken=SENT',
        ]),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $inboxPage = $service->initialBackfill();

    expect($inboxPage->cursor)->toBeNull()
        ->and($inboxPage->messageIds->all())->toEqual(['IN1'])
        ->and($inboxPage->nextPageToken)->not->toBeNull();

    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/mailFolders/inbox/messages/delta'));
    Http::assertNotSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/mailFolders/sentitems/messages/delta'));
    Http::assertNotSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/messages/delta'));

    $sentPage = $service->initialBackfill(null, $inboxPage->nextPageToken);

    expect($sentPage->nextPageToken)->toBeNull()
        ->and($sentPage->messageIds->all())->toEqual(['SE1'])
        ->and($sentPage->cursor)->toBe(microsoftMailCursor());

    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/mailFolders/sentitems/messages/delta'));
    Http::assertNotSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/mailFolders/drafts/'));
});

it('returns one backfill page and does not follow @odata.nextLink', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta' => Http::response([
            'value' => [
                ['id' => 'AAA1'],
            ],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=NEXT',
        ]),
    ]);

    $result = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->initialBackfill();

    expect($result->messageIds->all())->toEqual(['AAA1'])
        ->and($result->nextPageToken)->toContain('$skiptoken=NEXT')
        ->and($result->cursor)->toBeNull();

    Http::assertSentCount(1);
});

it('applies the optional initial_days cap as a receivedDateTime filter', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta*' => Http::response([
            'value' => [],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=INBOX',
        ]),
    ]);

    resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->initialBackfill(90);

    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/mailFolders/inbox/messages/delta')
        && str_contains(urldecode((string) $r->url()), 'receivedDateTime ge'));
});

it('applies the same receivedDateTime filter when SentItems backfill starts', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta*' => Http::response([
            'value' => [],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=INBOX',
        ]),
        'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta*' => Http::response([
            'value' => [],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta?$deltatoken=SENT',
        ]),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());
    $inboxPage = $service->initialBackfill(90);

    $service->initialBackfill(90, $inboxPage->nextPageToken);

    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/mailFolders/sentitems/messages/delta')
        && str_contains(urldecode((string) $r->url()), 'receivedDateTime ge'));
});

it('paginates delta with @odata.nextLink and surfaces new + read ids + new cursor', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=OLD' => Http::response([
            'value' => [
                ['id' => 'AAA1', 'isRead' => false],
            ],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=NEXT',
        ]),
        'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=NEXT' => Http::response([
            'value' => [
                ['id' => 'AAA2', 'isRead' => true],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=FRESH',
        ]),
        'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta?$deltatoken=SENT-OLD' => Http::response([
            'value' => [
                ['id' => 'SE1', 'isRead' => true],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta?$deltatoken=SENT-FRESH',
        ]),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $delta = $service->fetchDelta(microsoftMailCursor([
        'inbox' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=OLD',
        'sentitems' => 'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta?$deltatoken=SENT-OLD',
    ]));

    expect($delta->messageIds->all())->toEqual(['AAA1', 'AAA2', 'SE1'])
        ->and($delta->readMessageIds->all())->toEqual(['AAA2', 'SE1'])
        ->and($delta->newCursor)->toBe(microsoftMailCursor([
            'inbox' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=FRESH',
            'sentitems' => 'https://graph.microsoft.com/v1.0/me/mailFolders/sentitems/messages/delta?$deltatoken=SENT-FRESH',
        ]));
});

it('throws MailHistoryExpired when Graph returns 410 for a folder delta', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=EXPIRED' => Http::response('', 410),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    expect(fn (): MailDeltaResult => $service->fetchDelta(microsoftMailCursor([
        'inbox' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=EXPIRED',
    ])))->toThrow(MailHistoryExpired::class);
});

it('throws MailHistoryExpired for a legacy all-folder /me/messages/delta cursor', function (): void {
    Http::preventStrayRequests();

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    expect(fn (): MailDeltaResult => $service->fetchDelta('https://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=TKN'))
        ->toThrow(MailHistoryExpired::class);
});

it('maps a Graph drafts-folder message as an inbound draft', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/mailFolders*' => Http::response([
            'value' => [['id' => 'drafts-folder-id', 'displayName' => 'Drafts']],
        ]),
        'https://graph.microsoft.com/v1.0/me/messages/DRAFT1*' => Http::response([
            'id' => 'DRAFT1',
            'internetMessageId' => '<draft@example.com>',
            'conversationId' => 'thread-draft',
            'subject' => 'Unsent',
            'bodyPreview' => 'Still writing',
            'receivedDateTime' => '2026-01-15T10:00:00Z',
            'isRead' => true,
            'hasAttachments' => false,
            'parentFolderId' => 'drafts-folder-id',
            'from' => ['emailAddress' => ['address' => 'owner@example.com', 'name' => 'Owner']],
            'toRecipients' => [['emailAddress' => ['address' => 'prospect@example.com', 'name' => 'Prospect']]],
            'ccRecipients' => [],
            'bccRecipients' => [],
            'body' => ['contentType' => 'html', 'content' => '<p>Still writing</p>'],
        ]),
    ]);

    $email = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount())->fetchMessage('DRAFT1');

    expect($email->direction)->toBe(EmailDirection::INBOUND)
        ->and($email->folder)->toBe(EmailFolder::Drafts);
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
        'https://graph.microsoft.com/v1.0/me/mailFolders*' => Http::response([
            'value' => [['id' => 'inbox-folder-id', 'displayName' => 'Inbox']],
        ]),
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
