<?php

declare(strict_types=1);

use Google\Service\Gmail;
use Google\Service\Gmail\Message;
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
    return (string) base64_decode(strtr($message->getRaw(), '-_', '+/'));
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

    $result = (new GmailService($account, $gmail))->sendMessage([
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

    (new GmailService($account, $gmail))->sendMessage([
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

    (new GmailService($account, $gmail))->sendMessage([
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
