<?php

declare(strict_types=1);

use App\Data\DigestPayload;
use App\Listeners\Email\DropBouncedRecipientsListener;
use App\Mail\Concerns\ParksBouncedRecipients;
use App\Mail\ProTrialEndingSoonMail;
use App\Mail\TaskDigestMail;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Spatie\MailcoachMailer\MailcoachApiTransport;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;

mutates(ParksBouncedRecipients::class, DropBouncedRecipientsListener::class);

function useMailcoachAnswering(MockHttpClient $client): void
{
    Mail::mailer('array')->setSymfonyTransport((new MailcoachApiTransport('token', $client))->setHost('relaticle.mailcoach.test'));
}

function queueDigestThrough(MockHttpClient $client, User $user, string $expectedException = HttpTransportException::class): void
{
    useMailcoachAnswering($client);

    expect(fn () => Mail::mailer('array')->to($user)->queue(new TaskDigestMail($user, new DigestPayload([]))))
        ->toThrow($expectedException);
}

it('parks the recipient when the provider reports an inactive address', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    queueDigestThrough(new MockHttpClient(new MockResponse('', ['http_code' => 406])), $user);

    expect($user->fresh()->email_bounced_at)->not->toBeNull();
});

it('keeps the recipient on other provider errors', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    queueDigestThrough(new MockHttpClient(new MockResponse('', ['http_code' => 500])), $user);

    expect($user->fresh()->email_bounced_at)->toBeNull();
});

it('keeps the recipient when the provider cannot be reached', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $client = new MockHttpClient(fn (): never => throw new TransportException('SSL certificate problem: certificate has expired'));

    queueDigestThrough($client, $user, TransportException::class);

    expect($user->fresh()->email_bounced_at)->toBeNull();
});

it('parks the owner when the trial notice bounces', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'trial_ends_at' => now()->addDays(3)]);
    useMailcoachAnswering(new MockHttpClient(new MockResponse('', ['http_code' => 406])));

    expect(fn () => Mail::mailer('array')->to($owner)->queue(new ProTrialEndingSoonMail($workspace)))
        ->toThrow(HttpTransportException::class);

    expect($owner->fresh()->email_bounced_at)->not->toBeNull();
});

it('drops a parked recipient of parkable mail before the message reaches the transport', function (): void {
    $parked = User::factory()->withPersonalWorkspace()->create(['email_bounced_at' => now()]);

    Mail::mailer('array')->to($parked)->send(new TaskDigestMail($parked, new DigestPayload([])));

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toBeEmpty();
});

it('still sends essential mail to a parked recipient', function (): void {
    $parked = User::factory()->create(['email_bounced_at' => now()]);

    Mail::mailer('array')->raw('hello', fn (Message $message) => $message->to($parked->email));

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('still delivers parkable mail to the other recipients of a message', function (): void {
    $parked = User::factory()->create(['email_bounced_at' => now()]);
    $active = User::factory()->withPersonalWorkspace()->create();

    Mail::mailer('array')->to([$parked, $active])->send(new TaskDigestMail($active, new DigestPayload([])));

    $sent = Mail::mailer('array')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and(array_map(fn ($address): string => $address->getAddress(), $sent->first()->getOriginalMessage()->getTo()))->toBe([$active->email]);
});

it('delivers again once the user changes their email', function (): void {
    $user = User::factory()->create(['email_bounced_at' => now()]);

    $user->update(['email' => 'fresh@example.com']);

    expect($user->fresh()->email_bounced_at)->toBeNull();
});
