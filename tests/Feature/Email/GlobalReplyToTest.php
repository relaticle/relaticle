<?php

declare(strict_types=1);

use App\Mail\TaskAssignedMail;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

function sentReplyToAddresses(): array
{
    $transport = Mail::getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    $original = $transport->messages()->sole()->getOriginalMessage();
    assert($original instanceof Email);

    return collect($original->getReplyTo())->map->getAddress()->all();
}

it('stamps the configured global reply-to on outbound mail', function (): void {
    config()->set('mail.reply_to', ['address' => 'support@relaticle.com', 'name' => 'Relaticle']);

    Mail::to('assignee@example.com')->sendNow(new TaskAssignedMail('Follow up', 'https://relaticle.com'));

    expect(sentReplyToAddresses())->toBe(['support@relaticle.com']);
});

it('sends without a reply-to when none is configured', function (): void {
    Mail::to('assignee@example.com')->sendNow(new TaskAssignedMail('Follow up', 'https://relaticle.com'));

    expect(sentReplyToAddresses())->toBe([]);
});
