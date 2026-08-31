<?php

declare(strict_types=1);

use App\Mail\NewContactSubmissionMail;
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

it('keeps the submitter reply-to alongside the global one on contact mail', function (): void {
    config()->set('mail.reply_to', ['address' => 'support@relaticle.com', 'name' => 'Relaticle']);

    Mail::to('hello@relaticle.com')->sendNow(new NewContactSubmissionMail([
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'company' => null,
        'message' => 'I would like to learn more.',
    ]));

    expect(sentReplyToAddresses())->toContain('support@relaticle.com', 'jane@gmail.com');
});

it('sends without a reply-to when none is configured', function (): void {
    Mail::to('assignee@example.com')->sendNow(new TaskAssignedMail('Follow up', 'https://relaticle.com'));

    expect(sentReplyToAddresses())->toBe([]);
});
