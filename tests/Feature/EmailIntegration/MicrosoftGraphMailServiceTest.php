<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
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

it('sends a reply through createReply so Graph keeps the conversation', function (): void {
    Http::fake(function (Request $request) {
        $url = (string) $request->url();

        if ($request->method() === 'GET' && str_contains(urldecode($url), 'internetMessageId eq')) {
            return Http::response([
                'value' => [[
                    'id' => 'ORIG-ID',
                    'conversationId' => 'CONV-1',
                    'internetMessageId' => '<orig@example.com>',
                ]],
            ]);
        }

        if ($request->method() === 'POST' && str_ends_with($url, '/createReply')) {
            return Http::response([
                'id' => 'DRAFT-ID',
                'conversationId' => 'CONV-1',
            ], 201);
        }

        if ($request->method() === 'PATCH' && str_contains($url, '/me/messages/DRAFT-ID')) {
            return Http::response([
                'id' => 'DRAFT-ID',
                'conversationId' => 'CONV-1',
            ]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/me/messages/DRAFT-ID/attachments')) {
            return Http::response(['id' => 'ATT-1'], 201);
        }

        if ($request->method() === 'POST' && str_ends_with($url, '/send')) {
            return Http::response('', 202);
        }

        return Http::response(['error' => 'unexpected '.$request->method().' '.$url], 500);
    });

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $result = $service->sendMessage([
        'subject' => 'Re: Hello',
        'body_html' => '<p>Thanks</p>',
        'to' => [['email' => 'sender@example.com', 'name' => 'Sender']],
        'cc' => [['email' => 'cc@example.com', 'name' => 'Cc']],
        'in_reply_to' => '<orig@example.com>',
        'thread_id' => 'CONV-1',
        'rfc_message_id' => '<reply@example.com>',
        'attachments' => [[
            'filename' => 'notes.txt',
            'mime_type' => 'text/plain',
            'content' => 'NOTES',
        ]],
    ]);

    expect($result['thread_id'])->toBe('CONV-1')
        ->and($result['rfc_message_id'])->toBe('<reply@example.com>');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with((string) $r->url(), '/me/messages/ORIG-ID/createReply'));
    Http::assertSent(function (Request $r): bool {
        if ($r->method() !== 'PATCH' || ! str_contains((string) $r->url(), '/me/messages/DRAFT-ID')) {
            return false;
        }

        $payload = $r->data();

        return ($payload['subject'] ?? null) === 'Re: Hello'
            && ($payload['body']['content'] ?? null) === '<p>Thanks</p>'
            && ($payload['toRecipients'][0]['emailAddress']['address'] ?? null) === 'sender@example.com'
            && ($payload['ccRecipients'][0]['emailAddress']['address'] ?? null) === 'cc@example.com'
            && ($payload['singleValueExtendedProperties'][0]['value'] ?? null) === '<reply@example.com>'
            && ! array_key_exists('attachments', $payload);
    });
    Http::assertSent(function (Request $r): bool {
        if ($r->method() !== 'POST' || ! str_contains((string) $r->url(), '/me/messages/DRAFT-ID/attachments')) {
            return false;
        }

        $payload = $r->data();

        return ($payload['name'] ?? null) === 'notes.txt'
            && ($payload['contentBytes'] ?? null) === base64_encode('NOTES');
    });
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with((string) $r->url(), '/me/messages/DRAFT-ID/send'));
    Http::assertNotSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/sendMail'));
});

it('falls back to sendMail when the original Graph message cannot be found', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/messages*' => Http::response(['value' => []]),
        'https://graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202),
    ]);

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    $service->sendMessage([
        'subject' => 'Re: Hello',
        'body_html' => '<p>Thanks</p>',
        'to' => [['email' => 'sender@example.com', 'name' => 'Sender']],
        'in_reply_to' => '<missing@example.com>',
        'thread_id' => 'CONV-MISSING',
    ]);

    Http::assertSent(fn (Request $r): bool => str_contains((string) $r->url(), '/me/sendMail'));
    Http::assertNotSent(fn (Request $r): bool => str_contains((string) $r->url(), '/createReply'));
});

it('deletes the Graph reply draft when updating it fails', function (): void {
    Http::fake(function (Request $request) {
        $url = (string) $request->url();

        if ($request->method() === 'GET' && str_contains(urldecode($url), 'internetMessageId eq')) {
            return Http::response([
                'value' => [[
                    'id' => 'ORIG-ID',
                    'conversationId' => 'CONV-1',
                    'internetMessageId' => '<orig@example.com>',
                ]],
            ]);
        }

        if ($request->method() === 'POST' && str_ends_with($url, '/createReply')) {
            return Http::response([
                'id' => 'DRAFT-ID',
                'conversationId' => 'CONV-1',
            ], 201);
        }

        if ($request->method() === 'PATCH' && str_contains($url, '/me/messages/DRAFT-ID')) {
            return Http::response(['error' => ['message' => 'Invalid draft']], 400);
        }

        if ($request->method() === 'DELETE' && str_contains($url, '/me/messages/DRAFT-ID')) {
            return Http::response('', 204);
        }

        return Http::response(['error' => 'unexpected '.$request->method().' '.$url], 500);
    });

    $service = resolve(MicrosoftGraphServiceFactory::class)->make(makeAzureAccount());

    expect(fn () => $service->sendMessage([
        'subject' => 'Re: Hello',
        'body_html' => '<p>Thanks</p>',
        'to' => [['email' => 'sender@example.com', 'name' => 'Sender']],
        'in_reply_to' => '<orig@example.com>',
        'thread_id' => 'CONV-1',
    ]))->toThrow(RequestException::class);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/me/messages/DRAFT-ID'));
    Http::assertNotSent(fn (Request $r): bool => str_ends_with((string) $r->url(), '/send'));
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
