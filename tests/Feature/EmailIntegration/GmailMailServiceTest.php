<?php

declare(strict_types=1);

use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\History;
use Google\Service\Gmail\HistoryLabelAdded;
use Google\Service\Gmail\HistoryLabelRemoved;
use Google\Service\Gmail\HistoryMessageAdded;
use Google\Service\Gmail\ListHistoryResponse;
use Google\Service\Gmail\ListMessagesResponse;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartBody;
use Google\Service\Gmail\MessagePartHeader;
use Google\Service\Gmail\Profile;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Exceptions\MailHistoryExpired;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\GmailService;

mutates(GmailService::class);

/**
 * @param  Closure(Message): void  $capture
 */
function fakeGmail(Closure $capture): Gmail
{
    $messages = Mockery::mock();
    $messages->shouldReceive('send')
        ->once()
        ->andReturnUsing(function (string $userId, Message $message) use ($capture): Message {
            $capture($message);

            return new Message(['id' => 'gmail-id', 'threadId' => 'gmail-thread']);
        });

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_messages = $messages;

    return $gmail;
}

function decodeRaw(Message $message): string
{
    return base64_decode(strtr($message->getRaw(), '-_', '+/'));
}

/**
 * @param  Closure(string, array<string, mixed>): ListHistoryResponse  $responder
 */
function fakeGmailHistory(Closure $responder): Gmail
{
    $history = Mockery::mock();
    $history->shouldReceive('listUsersHistory')
        ->atLeast()
        ->once()
        ->andReturnUsing(fn (string $userId, array $params): ListHistoryResponse => $responder($userId, $params));

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_history = $history;

    return $gmail;
}

function gmailHistoryMessageAdded(string $id): HistoryMessageAdded
{
    $added = new HistoryMessageAdded;
    $added->setMessage(new Message(['id' => $id]));

    return $added;
}

/**
 * @param  list<string>  $labelIds
 */
function gmailHistoryLabelRemoved(string $id, array $labelIds): HistoryLabelRemoved
{
    $change = new HistoryLabelRemoved;
    $change->setMessage(new Message(['id' => $id]));
    $change->setLabelIds($labelIds);

    return $change;
}

/**
 * @param  list<string>  $labelIds
 */
function gmailHistoryLabelAdded(string $id, array $labelIds): HistoryLabelAdded
{
    $change = new HistoryLabelAdded;
    $change->setMessage(new Message(['id' => $id]));
    $change->setLabelIds($labelIds);

    return $change;
}

it('wraps the body and attachment in a multipart/mixed MIME message', function (): void {
    $account = ConnectedAccount::factory()->make([
        'email_address' => 'sender@example.com',
        'display_name' => 'Sender',
    ]);

    $captured = null;
    $gmail = fakeGmail(function (Message $message) use (&$captured): void {
        $captured = $message;
    });

    $result = new GmailService($account, $gmail)->sendMessage([
        'subject' => 'Quarterly report',
        'body_html' => '<p>See attached.</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'attachments' => [[
            'filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'content' => 'RAW-PDF-BYTES',
        ]],
    ]);

    $raw = decodeRaw($captured);

    expect($raw)->toContain('Content-Type: multipart/mixed; boundary="mixed_relaticle"')
        ->and($raw)->toContain('Content-Type: multipart/alternative; boundary="boundary_relaticle"')
        ->and($raw)->toContain('Content-Disposition: attachment; filename="report.pdf"')
        ->and($raw)->toContain(chunk_split(base64_encode('RAW-PDF-BYTES')));
});

it('embeds inline cid images in a multipart/related MIME part', function (): void {
    $account = ConnectedAccount::factory()->make([
        'email_address' => 'sender@example.com',
        'display_name' => 'Sender',
    ]);

    $captured = null;
    $gmail = fakeGmail(function (Message $message) use (&$captured): void {
        $captured = $message;
    });

    new GmailService($account, $gmail)->sendMessage([
        'subject' => 'Forwarded with logo',
        'body_html' => '<p><img src="cid:logo@example.test"></p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'attachments' => [[
            'filename' => 'logo.png',
            'mime_type' => 'image/png',
            'content' => 'PNG-BYTES',
            'is_inline' => true,
            'content_id' => 'logo@example.test',
        ]],
    ]);

    $raw = decodeRaw($captured);

    expect($raw)->toContain('Content-Type: multipart/related; boundary="related_relaticle"')
        ->and($raw)->not->toContain('multipart/mixed')
        ->and($raw)->toContain('Content-ID: <logo@example.test>')
        ->and($raw)->toContain('Content-Disposition: inline; filename="logo.png"')
        ->and($raw)->toContain('cid:logo@example.test')
        ->and($raw)->toContain(chunk_split(base64_encode('PNG-BYTES')));
});

it('sends a plain multipart/alternative message when there are no attachments', function (): void {
    $account = ConnectedAccount::factory()->make([
        'email_address' => 'sender@example.com',
        'display_name' => 'Sender',
    ]);

    $captured = null;
    $gmail = fakeGmail(function (Message $message) use (&$captured): void {
        $captured = $message;
    });

    new GmailService($account, $gmail)->sendMessage([
        'subject' => 'No attachment',
        'body_html' => '<p>Body</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
    ]);

    $raw = decodeRaw($captured);

    expect($raw)->toContain('Content-Type: multipart/alternative; boundary="boundary_relaticle"')
        ->and($raw)->not->toContain('multipart/mixed');
});

it('threads a same-mailbox reply with the provider thread id', function (): void {
    $account = ConnectedAccount::factory()->make([
        'email_address' => 'sender@example.com',
        'display_name' => 'Sender',
    ]);

    $captured = null;
    $gmail = fakeGmail(function (Message $message) use (&$captured): void {
        $captured = $message;
    });

    new GmailService($account, $gmail)->sendMessage([
        'subject' => 'Re: Hello',
        'body_html' => '<p>Reply</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'in_reply_to' => '<original@example.com>',
        'thread_id' => 'gmail-thread-abc',
    ]);

    expect($captured->getThreadId())->toBe('gmail-thread-abc');
});

it('sends a reply without a provider thread id when the thread belongs to another mailbox', function (): void {
    $account = ConnectedAccount::factory()->make([
        'email_address' => 'sender@example.com',
        'display_name' => 'Sender',
    ]);

    $captured = null;
    $gmail = fakeGmail(function (Message $message) use (&$captured): void {
        $captured = $message;
    });

    new GmailService($account, $gmail)->sendMessage([
        'subject' => 'Re: Hello',
        'body_html' => '<p>Reply from another mailbox</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'in_reply_to' => '<original@example.com>',
    ]);

    $raw = decodeRaw($captured);

    expect($captured->getThreadId())->toBeNull()
        ->and($raw)->toContain('In-Reply-To: <original@example.com>');
});

it('strips quotes and newlines from attachment filenames to prevent header injection', function (): void {
    $account = ConnectedAccount::factory()->make([
        'email_address' => 'sender@example.com',
        'display_name' => 'Sender',
    ]);

    $captured = null;
    $gmail = fakeGmail(function (Message $message) use (&$captured): void {
        $captured = $message;
    });

    new GmailService($account, $gmail)->sendMessage([
        'subject' => 'Injection attempt',
        'body_html' => '<p>Body</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'attachments' => [[
            'filename' => "evil\"\r\nBcc: attacker@example.com",
            'mime_type' => 'text/plain',
            'content' => 'x',
        ]],
    ]);

    $raw = decodeRaw($captured);

    expect($raw)->toContain('filename="evilBcc: attacker@example.com"')
        ->and($raw)->not->toContain("\r\nBcc: attacker@example.com");
});

it('marks gmail cid image parts as inline instead of downloadable attachments', function (): void {
    $account = ConnectedAccount::factory()->make();

    $imagePart = new MessagePart;
    $imagePart->setFilename('logo.png');
    $imagePart->setMimeType('image/png');
    $imagePart->setHeaders([
        new MessagePartHeader(['name' => 'Content-ID', 'value' => '<logo@example.test>']),
        new MessagePartHeader(['name' => 'Content-Disposition', 'value' => 'inline; filename="logo.png"']),
    ]);
    $imagePart->setBody(new MessagePartBody([
        'attachmentId' => 'image-attachment-id',
        'size' => 1234,
    ]));

    $htmlPart = new MessagePart;
    $htmlPart->setMimeType('text/html');
    $htmlPart->setBody(new MessagePartBody([
        'data' => rtrim(strtr(base64_encode('<p><img src="cid:logo@example.test"></p>'), '+/', '-_'), '='),
        'size' => 43,
    ]));

    $payload = new MessagePart;
    $payload->setMimeType('multipart/related');
    $payload->setHeaders([
        new MessagePartHeader(['name' => 'Message-ID', 'value' => '<msg@example.test>']),
        new MessagePartHeader(['name' => 'Subject', 'value' => 'Inline image']),
        new MessagePartHeader(['name' => 'From', 'value' => 'Sender <sender@example.test>']),
        new MessagePartHeader(['name' => 'To', 'value' => 'Owner <owner@example.test>']),
    ]);
    $payload->setParts([$htmlPart, $imagePart]);

    $message = new Message([
        'id' => 'gmail-msg-inline',
        'threadId' => 'gmail-thread-inline',
        'internalDate' => (string) (now()->timestamp * 1000),
        'labelIds' => ['INBOX'],
        'snippet' => 'Inline image',
    ]);
    $message->setPayload($payload);

    $messages = Mockery::mock();
    $messages->shouldReceive('get')
        ->once()
        ->with('me', 'gmail-msg-inline', ['format' => 'full'])
        ->andReturn($message);

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_messages = $messages;

    $data = new GmailService($account, $gmail)->fetchMessage('gmail-msg-inline');

    expect($data->hasAttachments)->toBeFalse()
        ->and($data->attachments)->toHaveCount(1)
        ->and($data->attachments[0]['filename'])->toBe('logo.png')
        ->and($data->attachments[0]['content_id'])->toBe('logo@example.test')
        ->and($data->attachments[0]['attachment_id'])->toBe('image-attachment-id')
        ->and($data->attachments[0]['is_inline'])->toBeTrue();
});

it('marks body-referenced gmail attachment-disposition cid images inline', function (): void {
    $account = ConnectedAccount::factory()->make();

    $imagePart = new MessagePart;
    $imagePart->setFilename('photo.png');
    $imagePart->setMimeType('image/png');
    $imagePart->setHeaders([
        new MessagePartHeader(['name' => 'Content-ID', 'value' => '<photo@example.test>']),
        new MessagePartHeader(['name' => 'Content-Disposition', 'value' => 'attachment; filename="photo.png"']),
    ]);
    $imagePart->setBody(new MessagePartBody([
        'attachmentId' => 'photo-attachment-id',
        'size' => 1234,
    ]));

    $htmlPart = new MessagePart;
    $htmlPart->setMimeType('text/html');
    $htmlPart->setBody(new MessagePartBody([
        'data' => rtrim(strtr(base64_encode('<p><img src="cid:photo@example.test"></p>'), '+/', '-_'), '='),
        'size' => 44,
    ]));

    $payload = new MessagePart;
    $payload->setMimeType('multipart/related');
    $payload->setHeaders([
        new MessagePartHeader(['name' => 'Message-ID', 'value' => '<msg@example.test>']),
        new MessagePartHeader(['name' => 'Subject', 'value' => 'Image attachment']),
        new MessagePartHeader(['name' => 'From', 'value' => 'Sender <sender@example.test>']),
        new MessagePartHeader(['name' => 'To', 'value' => 'Owner <owner@example.test>']),
    ]);
    $payload->setParts([$htmlPart, $imagePart]);

    $message = new Message([
        'id' => 'gmail-msg-attachment-image',
        'threadId' => 'gmail-thread-attachment-image',
        'internalDate' => (string) (now()->timestamp * 1000),
        'labelIds' => ['INBOX'],
        'snippet' => 'Image attachment',
    ]);
    $message->setPayload($payload);

    $messages = Mockery::mock();
    $messages->shouldReceive('get')
        ->once()
        ->with('me', 'gmail-msg-attachment-image', ['format' => 'full'])
        ->andReturn($message);

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_messages = $messages;

    $data = new GmailService($account, $gmail)->fetchMessage('gmail-msg-attachment-image');

    expect($data->hasAttachments)->toBeFalse()
        ->and($data->attachments)->toHaveCount(1)
        ->and($data->attachments[0]['filename'])->toBe('photo.png')
        ->and($data->attachments[0]['content_id'])->toBe('photo@example.test')
        ->and($data->attachments[0]['attachment_id'])->toBe('photo-attachment-id')
        ->and($data->attachments[0]['is_inline'])->toBeTrue();
});

it('imports a file when the gmail payload itself is the attachment', function (): void {
    $account = ConnectedAccount::factory()->make();

    $payload = new MessagePart;
    $payload->setMimeType('application/pdf');
    $payload->setFilename('invoice.pdf');
    $payload->setHeaders([
        new MessagePartHeader(['name' => 'Message-ID', 'value' => '<msg@example.test>']),
        new MessagePartHeader(['name' => 'Subject', 'value' => 'Invoice']),
        new MessagePartHeader(['name' => 'From', 'value' => 'Sender <sender@example.test>']),
        new MessagePartHeader(['name' => 'To', 'value' => 'Owner <owner@example.test>']),
        new MessagePartHeader(['name' => 'Content-Disposition', 'value' => 'attachment; filename="invoice.pdf"']),
    ]);
    $payload->setBody(new MessagePartBody([
        'attachmentId' => 'pdf-attachment-id',
        'size' => 2048,
    ]));

    $message = new Message([
        'id' => 'gmail-msg-root-pdf',
        'threadId' => 'gmail-thread-root-pdf',
        'internalDate' => (string) (now()->timestamp * 1000),
        'labelIds' => ['INBOX'],
        'snippet' => 'Invoice',
    ]);
    $message->setPayload($payload);

    $messages = Mockery::mock();
    $messages->shouldReceive('get')
        ->once()
        ->with('me', 'gmail-msg-root-pdf', ['format' => 'full'])
        ->andReturn($message);

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_messages = $messages;

    $data = new GmailService($account, $gmail)->fetchMessage('gmail-msg-root-pdf');

    expect($data->hasAttachments)->toBeTrue()
        ->and($data->attachments)->toHaveCount(1)
        ->and($data->attachments[0]['filename'])->toBe('invoice.pdf')
        ->and($data->attachments[0]['mime_type'])->toBe('application/pdf')
        ->and($data->attachments[0]['size'])->toBe(2048)
        ->and($data->attachments[0]['attachment_id'])->toBe('pdf-attachment-id')
        ->and($data->attachments[0]['is_inline'])->toBeFalse();
});

it('does not treat a root text payload as an attachment', function (): void {
    $account = ConnectedAccount::factory()->make();

    $payload = new MessagePart;
    $payload->setMimeType('text/plain');
    $payload->setHeaders([
        new MessagePartHeader(['name' => 'Message-ID', 'value' => '<msg@example.test>']),
        new MessagePartHeader(['name' => 'Subject', 'value' => 'Hello']),
        new MessagePartHeader(['name' => 'From', 'value' => 'Sender <sender@example.test>']),
        new MessagePartHeader(['name' => 'To', 'value' => 'Owner <owner@example.test>']),
    ]);
    $payload->setBody(new MessagePartBody([
        'data' => rtrim(strtr(base64_encode('Hello'), '+/', '-_'), '='),
        'size' => 5,
    ]));

    $message = new Message([
        'id' => 'gmail-msg-plain',
        'threadId' => 'gmail-thread-plain',
        'internalDate' => (string) (now()->timestamp * 1000),
        'labelIds' => ['INBOX'],
        'snippet' => 'Hello',
    ]);
    $message->setPayload($payload);

    $messages = Mockery::mock();
    $messages->shouldReceive('get')
        ->once()
        ->with('me', 'gmail-msg-plain', ['format' => 'full'])
        ->andReturn($message);

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_messages = $messages;

    $data = new GmailService($account, $gmail)->fetchMessage('gmail-msg-plain');

    expect($data->hasAttachments)->toBeFalse()
        ->and($data->attachments)->toBe([])
        ->and($data->bodyText)->toBe('Hello');
});

it('keeps unreferenced gmail attachment-disposition cid images downloadable', function (): void {
    $account = ConnectedAccount::factory()->make();

    $imagePart = new MessagePart;
    $imagePart->setFilename('photo.png');
    $imagePart->setMimeType('image/png');
    $imagePart->setHeaders([
        new MessagePartHeader(['name' => 'Content-ID', 'value' => '<photo@example.test>']),
        new MessagePartHeader(['name' => 'Content-Disposition', 'value' => 'attachment; filename="photo.png"']),
    ]);
    $imagePart->setBody(new MessagePartBody([
        'attachmentId' => 'photo-attachment-id',
        'size' => 1234,
    ]));

    $payload = new MessagePart;
    $payload->setHeaders([
        new MessagePartHeader(['name' => 'Message-ID', 'value' => '<msg@example.test>']),
        new MessagePartHeader(['name' => 'Subject', 'value' => 'Image attachment']),
        new MessagePartHeader(['name' => 'From', 'value' => 'Sender <sender@example.test>']),
        new MessagePartHeader(['name' => 'To', 'value' => 'Owner <owner@example.test>']),
    ]);
    $payload->setParts([$imagePart]);

    $message = new Message([
        'id' => 'gmail-msg-downloadable-image',
        'threadId' => 'gmail-thread-downloadable-image',
        'internalDate' => (string) (now()->timestamp * 1000),
        'labelIds' => ['INBOX'],
        'snippet' => 'Image attachment',
    ]);
    $message->setPayload($payload);

    $messages = Mockery::mock();
    $messages->shouldReceive('get')
        ->once()
        ->with('me', 'gmail-msg-downloadable-image', ['format' => 'full'])
        ->andReturn($message);

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_messages = $messages;

    $data = new GmailService($account, $gmail)->fetchMessage('gmail-msg-downloadable-image');

    expect($data->hasAttachments)->toBeTrue()
        ->and($data->attachments)->toHaveCount(1)
        ->and($data->attachments[0]['filename'])->toBe('photo.png')
        ->and($data->attachments[0]['content_id'])->toBe('photo@example.test')
        ->and($data->attachments[0]['attachment_id'])->toBe('photo-attachment-id')
        ->and($data->attachments[0]['is_inline'])->toBeFalse();
});

it('requests gmail history with singular history type enums', function (): void {
    $account = ConnectedAccount::factory()->make();
    $captured = [];

    $history = new History;
    $history->setMessagesAdded([gmailHistoryMessageAdded('msg-new')]);

    $response = new ListHistoryResponse;
    $response->setHistoryId('4000');
    $response->setHistory([$history]);

    $gmail = fakeGmailHistory(function (string $userId, array $params) use (&$captured, $response): ListHistoryResponse {
        $captured[] = ['userId' => $userId, 'params' => $params];

        return $response;
    });

    new GmailService($account, $gmail)->fetchDelta('3891');

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['userId'])->toBe('me')
        ->and($captured[0]['params'])->toBe([
            'startHistoryId' => '3891',
            'historyTypes' => ['messageAdded', 'labelRemoved', 'labelAdded'],
        ]);
});

it('surfaces new, read, and unread message ids from gmail history', function (): void {
    $account = ConnectedAccount::factory()->make();

    $history = new History;
    $history->setMessagesAdded([gmailHistoryMessageAdded('msg-new')]);
    $history->setLabelsRemoved([
        gmailHistoryLabelRemoved('msg-read', ['UNREAD']),
        gmailHistoryLabelRemoved('msg-starred', ['STARRED']),
    ]);
    $history->setLabelsAdded([
        gmailHistoryLabelAdded('msg-unread', ['UNREAD']),
        gmailHistoryLabelAdded('msg-important', ['IMPORTANT']),
    ]);

    $response = new ListHistoryResponse;
    $response->setHistoryId('4000');
    $response->setHistory([$history]);

    $gmail = fakeGmailHistory(fn (): ListHistoryResponse => $response);

    $delta = new GmailService($account, $gmail)->fetchDelta('3891');

    expect($delta->messageIds->all())->toBe(['msg-new'])
        ->and($delta->readMessageIds->all())->toBe(['msg-read'])
        ->and($delta->unreadMessageIds?->all())->toBe(['msg-unread'])
        ->and($delta->newCursor)->toBe('4000');
});

it('keeps only the last unread-label change when a message is marked unread then read in one sync', function (): void {
    $account = ConnectedAccount::factory()->make();

    $markedUnread = new History;
    $markedUnread->setLabelsAdded([gmailHistoryLabelAdded('msg-flip', ['UNREAD'])]);

    $markedRead = new History;
    $markedRead->setLabelsRemoved([gmailHistoryLabelRemoved('msg-flip', ['UNREAD'])]);

    $response = new ListHistoryResponse;
    $response->setHistoryId('4000');
    $response->setHistory([$markedUnread, $markedRead]);

    $gmail = fakeGmailHistory(fn (): ListHistoryResponse => $response);

    $delta = new GmailService($account, $gmail)->fetchDelta('3891');

    expect($delta->readMessageIds->all())->toBe(['msg-flip'])
        ->and($delta->unreadMessageIds?->all())->toBe([]);
});

it('keeps only the last unread-label change when a message is marked read then unread in one sync', function (): void {
    $account = ConnectedAccount::factory()->make();

    $markedRead = new History;
    $markedRead->setLabelsRemoved([gmailHistoryLabelRemoved('msg-flip', ['UNREAD'])]);

    $markedUnread = new History;
    $markedUnread->setLabelsAdded([gmailHistoryLabelAdded('msg-flip', ['UNREAD'])]);

    $response = new ListHistoryResponse;
    $response->setHistoryId('4000');
    $response->setHistory([$markedRead, $markedUnread]);

    $gmail = fakeGmailHistory(fn (): ListHistoryResponse => $response);

    $delta = new GmailService($account, $gmail)->fetchDelta('3891');

    expect($delta->unreadMessageIds?->all())->toBe(['msg-flip'])
        ->and($delta->readMessageIds->all())->toBe([]);
});

it('keeps only the last unread-label change when the flip spans history pages', function (): void {
    $account = ConnectedAccount::factory()->make();

    $firstHistory = new History;
    $firstHistory->setLabelsAdded([gmailHistoryLabelAdded('msg-flip', ['UNREAD'])]);

    $firstPage = new ListHistoryResponse;
    $firstPage->setHistoryId('4100');
    $firstPage->setNextPageToken('page-2');
    $firstPage->setHistory([$firstHistory]);

    $secondHistory = new History;
    $secondHistory->setLabelsRemoved([gmailHistoryLabelRemoved('msg-flip', ['UNREAD'])]);

    $secondPage = new ListHistoryResponse;
    $secondPage->setHistoryId('4100');
    $secondPage->setHistory([$secondHistory]);

    $gmail = fakeGmailHistory(function (string $userId, array $params) use ($firstPage, $secondPage): ListHistoryResponse {
        return isset($params['pageToken']) ? $secondPage : $firstPage;
    });

    $delta = new GmailService($account, $gmail)->fetchDelta('3891');

    expect($delta->readMessageIds->all())->toBe(['msg-flip'])
        ->and($delta->unreadMessageIds?->all())->toBe([]);
});

it('follows gmail history pages until the token is exhausted', function (): void {
    $account = ConnectedAccount::factory()->make();
    $captured = [];

    $firstHistory = new History;
    $firstHistory->setMessagesAdded([gmailHistoryMessageAdded('msg-page-1')]);

    $firstPage = new ListHistoryResponse;
    $firstPage->setHistoryId('4100');
    $firstPage->setNextPageToken('page-2');
    $firstPage->setHistory([$firstHistory]);

    $secondHistory = new History;
    $secondHistory->setMessagesAdded([gmailHistoryMessageAdded('msg-page-2')]);

    $secondPage = new ListHistoryResponse;
    $secondPage->setHistoryId('4100');
    $secondPage->setHistory([$secondHistory]);

    $gmail = fakeGmailHistory(function (string $userId, array $params) use (&$captured, $firstPage, $secondPage): ListHistoryResponse {
        $captured[] = $params;

        return isset($params['pageToken']) ? $secondPage : $firstPage;
    });

    $delta = new GmailService($account, $gmail)->fetchDelta('3891');

    expect($captured)->toHaveCount(2)
        ->and($captured[0])->toBe([
            'startHistoryId' => '3891',
            'historyTypes' => ['messageAdded', 'labelRemoved', 'labelAdded'],
        ])
        ->and($captured[1])->toBe([
            'startHistoryId' => '3891',
            'historyTypes' => ['messageAdded', 'labelRemoved', 'labelAdded'],
            'pageToken' => 'page-2',
        ])
        ->and($delta->messageIds->all())->toBe(['msg-page-1', 'msg-page-2'])
        ->and($delta->newCursor)->toBe('4100');
});

it('requires a full mailbox resync when Gmail history returns 404 for a stale cursor', function (): void {
    $account = ConnectedAccount::factory()->make();

    $history = Mockery::mock();
    $history->shouldReceive('listUsersHistory')
        ->once()
        ->andThrow(new GoogleServiceException('Requested entity was not found.', 404));

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_history = $history;

    expect(fn () => new GmailService($account, $gmail)->fetchDelta('stale-history'))
        ->toThrow(MailHistoryExpired::class);
});

it('does not treat other Gmail history HTTP errors as an expired cursor', function (): void {
    $account = ConnectedAccount::factory()->make();

    $history = Mockery::mock();
    $history->shouldReceive('listUsersHistory')
        ->once()
        ->andThrow(new GoogleServiceException('Backend Error', 500));

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_history = $history;

    expect(fn () => new GmailService($account, $gmail)->fetchDelta('history-1'))
        ->toThrow(GoogleServiceException::class);
});

it('classifies a DRAFT-labeled message as an inbound draft', function (): void {
    $account = ConnectedAccount::factory()->make();

    $payload = new MessagePart;
    $payload->setHeaders([
        new MessagePartHeader(['name' => 'Message-ID', 'value' => '<draft@example.test>']),
        new MessagePartHeader(['name' => 'Subject', 'value' => 'Unsent']),
        new MessagePartHeader(['name' => 'From', 'value' => 'Owner <owner@example.test>']),
        new MessagePartHeader(['name' => 'To', 'value' => 'Prospect <prospect@example.test>']),
    ]);
    $payload->setParts([]);

    $message = new Message([
        'id' => 'gmail-draft-1',
        'threadId' => 'gmail-thread-draft',
        'internalDate' => (string) (now()->timestamp * 1000),
        'labelIds' => ['DRAFT'],
        'snippet' => 'Unsent',
    ]);
    $message->setPayload($payload);

    $messages = Mockery::mock();
    $messages->shouldReceive('get')
        ->once()
        ->with('me', 'gmail-draft-1', ['format' => 'full'])
        ->andReturn($message);

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users_messages = $messages;

    $data = new GmailService($account, $gmail)->fetchMessage('gmail-draft-1');

    expect($data->direction)->toBe(EmailDirection::INBOUND)
        ->and($data->folder)->toBe(EmailFolder::Drafts);
});

it('excludes drafts from the initial backfill listing', function (): void {
    $account = ConnectedAccount::factory()->make();
    $captured = [];

    $profile = new Profile;
    $profile->setHistoryId('1000');

    $users = Mockery::mock();
    $users->shouldReceive('getProfile')->once()->with('me')->andReturn($profile);

    $messages = Mockery::mock();
    $messages->shouldReceive('listUsersMessages')
        ->once()
        ->andReturnUsing(function (string $userId, array $params) use (&$captured): ListMessagesResponse {
            $captured = ['userId' => $userId, 'params' => $params];

            return new ListMessagesResponse;
        });

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users = $users;
    $gmail->users_messages = $messages;

    new GmailService($account, $gmail)->initialBackfill();

    expect($captured['userId'])->toBe('me')
        ->and($captured['params']['q'])->toBe('-in:drafts');
});

it('keeps the draft exclusion when the initial backfill is date-capped', function (): void {
    $this->travelTo('2026-09-09 12:00:00');

    $account = ConnectedAccount::factory()->make();
    $captured = [];

    $profile = new Profile;
    $profile->setHistoryId('1000');

    $users = Mockery::mock();
    $users->shouldReceive('getProfile')->once()->with('me')->andReturn($profile);

    $messages = Mockery::mock();
    $messages->shouldReceive('listUsersMessages')
        ->once()
        ->andReturnUsing(function (string $userId, array $params) use (&$captured): ListMessagesResponse {
            $captured = $params;

            return new ListMessagesResponse;
        });

    $gmail = Mockery::mock(Gmail::class);
    $gmail->users = $users;
    $gmail->users_messages = $messages;

    new GmailService($account, $gmail)->initialBackfill(90);

    expect($captured['q'])->toBe('-in:drafts after:1781179200');
});
