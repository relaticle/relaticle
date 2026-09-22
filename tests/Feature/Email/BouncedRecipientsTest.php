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
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

mutates(ParksBouncedRecipients::class, DropBouncedRecipientsListener::class);

function failingTransport(int $status): TransportInterface
{
    return new readonly class($status) implements TransportInterface
    {
        public function __construct(private int $status) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new HttpTransportException('Unable to send an email', new MockResponse('', ['http_code' => $this->status]));
        }

        public function __toString(): string
        {
            return 'failing';
        }
    };
}

function queueDigestThrough(int $status, User $user): void
{
    Mail::mailer('array')->setSymfonyTransport(failingTransport($status));

    expect(fn () => Mail::mailer('array')->to($user)->queue(new TaskDigestMail($user, new DigestPayload([]))))
        ->toThrow(HttpTransportException::class);
}

it('parks the recipient when the provider reports an inactive address', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    queueDigestThrough(406, $user);

    expect($user->fresh()->email_bounced_at)->not->toBeNull();
});

it('keeps the recipient on other transport failures', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    queueDigestThrough(500, $user);

    expect($user->fresh()->email_bounced_at)->toBeNull();
});

it('parks the owner when the trial notice bounces', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'trial_ends_at' => now()->addDays(3)]);
    Mail::mailer('array')->setSymfonyTransport(failingTransport(406));

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
