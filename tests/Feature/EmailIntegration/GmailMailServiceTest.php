<?php

declare(strict_types=1);

use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartBody;
use Google\Service\Gmail\MessagePartHeader;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Exceptions\MailHistoryExpired;
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

    expect($result['provider_message_id'])->toBe('gmail-id')
        ->and($raw)->toContain('Content-Type: multipart/mixed; boundary="mixed_relaticle"')
        ->and($raw)->toContain('Content-Type: multipart/alternative; boundary="boundary_relaticle"')
        ->and($raw)->toContain('Content-Disposition: attachment; filename="report.pdf"')
        ->and($raw)->toContain(chunk_split(base64_encode('RAW-PDF-BYTES')));
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
