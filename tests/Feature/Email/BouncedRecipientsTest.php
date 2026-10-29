<?php

declare(strict_types=1);

use App\Data\DigestPayload;
use App\Enums\Plan;
use App\Listeners\Email\DropBouncedRecipientsListener;
use App\Listeners\Email\ParkInactiveRecipientsListener;
use App\Mail\ProEndedMail;
use App\Mail\ProTrialEndingSoonMail;
use App\Mail\SetupNudgeMail;
use App\Mail\TaskAssignedMail;
use App\Mail\TaskDigestMail;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Mail\ParksInactiveRecipientsTransport;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Spatie\MailcoachMailer\Exceptions\InvalidMailerConfig;
use Spatie\MailcoachMailer\MailcoachApiTransport;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;

mutates(ParksInactiveRecipientsTransport::class, ParkInactiveRecipientsListener::class, DropBouncedRecipientsListener::class);

function useMailcoachAnswering(MockHttpClient $client): void
{
    Mail::mailer('array')->setSymfonyTransport(new ParksInactiveRecipientsTransport(
        new MailcoachApiTransport('token', $client)->setHost('relaticle.mailcoach.test'),
    ));
}

function queueDigestThrough(MockHttpClient $client, User $user, string $expectedException): void
{
    useMailcoachAnswering($client);

    expect(fn () => Mail::mailer('array')->to($user)->queue(new TaskDigestMail($user, new DigestPayload([]))))
        ->toThrow($expectedException);
}

it('parks the recipient when the provider reports an inactive address', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    useMailcoachAnswering(new MockHttpClient(new MockResponse('', ['http_code' => 406])));

    Mail::mailer('array')->to($user)->queue(new TaskDigestMail($user, new DigestPayload([])));

    expect($user->fresh()->email_bounced_at)->not->toBeNull();
});

it('keeps the recipient on other provider errors', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    queueDigestThrough(new MockHttpClient(new MockResponse('', ['http_code' => 500])), $user, HttpTransportException::class);

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

    Mail::mailer('array')->to($owner)->queue(new ProTrialEndingSoonMail($workspace));

    expect($owner->fresh()->email_bounced_at)->not->toBeNull();
});

it('drops a parked recipient of parkable mail before the message reaches the transport', function (): void {
    $parked = User::factory()->withPersonalWorkspace()->create(['email_bounced_at' => now()]);

    Mail::mailer('array')->to($parked)->send(new TaskDigestMail($parked, new DigestPayload([])));

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toBeEmpty();
});

it('still sends a raw message to a parked recipient', function (): void {
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

dataset('non-essential mailables', [
    'trial ended' => fn (User $user, Workspace $workspace): Mailable => ProEndedMail::afterTrial($workspace),
    'subscription ended' => fn (User $user, Workspace $workspace): Mailable => ProEndedMail::afterSubscription($workspace),
    'managed plan ended' => fn (User $user, Workspace $workspace): Mailable => ProEndedMail::afterManagedPlan($workspace, Plan::Concierge),
    'setup nudge' => fn (User $user, Workspace $workspace): Mailable => new SetupNudgeMail($user, $workspace, 'first_record', 'https://relaticle.test/chat'),
    'task assigned' => fn (User $user, Workspace $workspace): Mailable => new TaskAssignedMail('Call back', 'https://relaticle.test/tasks', $workspace->name),
]);

it('drops a parked recipient of every non-essential mailable', function (Closure $makeMailable): void {
    $parked = User::factory()->withPersonalWorkspace()->create(['email_bounced_at' => now()]);

    Mail::mailer('array')->to($parked)->send($makeMailable($parked, $parked->currentWorkspace));

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toBeEmpty();
})->with('non-essential mailables');

it('parks the recipient when any non-essential mailable bounces', function (Closure $makeMailable): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    useMailcoachAnswering(new MockHttpClient(new MockResponse('', ['http_code' => 406])));

    Mail::mailer('array')->to($user)->queue($makeMailable($user, $user->currentWorkspace));

    expect($user->fresh()->email_bounced_at)->not->toBeNull();
})->with('non-essential mailables');

it('does not send the trial ended notice to an owner parked by the trial reminder', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create(['email_bounced_at' => now()]);
    $owner->currentWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subHour()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toBeEmpty();
});

it('does not fail or report the queued mailable when the provider rejects an inactive recipient', function (): void {
    Exceptions::fake();
    $user = User::factory()->withPersonalWorkspace()->create();
    useMailcoachAnswering(new MockHttpClient(new MockResponse('', ['http_code' => 406])));

    Mail::mailer('array')->to($user)->queue(new TaskDigestMail($user, new DigestPayload([])));

    Exceptions::assertNotReported(HttpTransportException::class);
    expect($user->fresh()->email_bounced_at)->not->toBeNull();
});

it('still sends essential mail to a parked recipient', function (): void {
    $parked = User::factory()->create(['email_bounced_at' => now()]);
    $invitation = WorkspaceInvitation::factory()->create(['email' => $parked->email]);

    Mail::mailer('array')->to($parked)->send(new WorkspaceInvitationMail($invitation, $invitation->issueToken()));

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('still sends a password reset notification to a parked user', function (): void {
    $parked = User::factory()->create(['email_bounced_at' => now()]);

    Password::sendResetLink(['email' => $parked->email]);

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('parks the user without failing when a notification bounces', function (): void {
    $user = User::factory()->create();
    useMailcoachAnswering(new MockHttpClient(new MockResponse('', ['http_code' => 406])));

    Password::sendResetLink(['email' => $user->email]);

    expect($user->fresh()->email_bounced_at)->not->toBeNull();
});

it('parks the recipient without failing when a plain send bounces', function (): void {
    $user = User::factory()->create();
    useMailcoachAnswering(new MockHttpClient(new MockResponse('', ['http_code' => 406])));

    Mail::mailer('array')->raw('hello', fn (Message $message) => $message->to($user->email));

    expect($user->fresh()->email_bounced_at)->not->toBeNull();
});

it('parks nobody and keeps failing when a message with several recipients bounces', function (): void {
    $first = User::factory()->withPersonalWorkspace()->create();
    $second = User::factory()->create();
    useMailcoachAnswering(new MockHttpClient(new MockResponse('', ['http_code' => 406])));

    expect(fn () => Mail::mailer('array')->to([$first, $second])->send(new TaskDigestMail($first, new DigestPayload([]))))
        ->toThrow(HttpTransportException::class);

    expect($first->fresh()->email_bounced_at)->toBeNull()
        ->and($second->fresh()->email_bounced_at)->toBeNull();
});

it('parks nobody and keeps failing when a copied message bounces', function (): void {
    $user = User::factory()->create();
    useMailcoachAnswering(new MockHttpClient(new MockResponse('', ['http_code' => 406])));

    expect(fn () => Mail::mailer('array')->raw('hello', fn (Message $message) => $message->to($user->email)->cc('copy@example.com')))
        ->toThrow(HttpTransportException::class);

    expect($user->fresh()->email_bounced_at)->toBeNull();
});

it('builds the configured mailcoach mailer on the parking transport', function (): void {
    config(['mail.mailers.mailcoach.domain' => 'relaticle.mailcoach.test', 'mail.mailers.mailcoach.token' => 'token']);
    Mail::purge('mailcoach');

    expect(Mail::mailer('mailcoach')->getSymfonyTransport())->toBeInstanceOf(ParksInactiveRecipientsTransport::class);
});

it('names the missing mailcoach setting when the mailer is not configured', function (): void {
    config(['mail.mailers.mailcoach.domain' => null, 'mail.mailers.mailcoach.token' => 'token']);
    Mail::purge('mailcoach');

    expect(fn () => Mail::mailer('mailcoach'))->toThrow(InvalidMailerConfig::class);
});
