<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\EmailSendingService;

mutates(SendEmailAction::class, EmailSendingService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
    ]));
});

it('persists a queued Email row for the outbox', function (): void {
    $sendData = [
        'connected_account_id' => $this->account->id,
        'subject' => 'Hello World',
        'body_html' => '<p>Test</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => 'Recipient']],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ];

    $email = app(SendEmailAction::class)->execute($sendData);

    expect($email->status)->toBe(EmailStatus::QUEUED)
        ->and($email->direction)->toBe(EmailDirection::OUTBOUND)
        ->and($email->connected_account_id)->toBe($this->account->id)
        ->and($email->subject)->toBe('Hello World')
        ->and($email->batch_id)->toBeNull()
        // A stable Message-ID is stamped at queue time for retry de-duplication.
        ->and($email->rfc_message_id)->toMatch('/^<[0-9A-Za-z]+@.+>$/');
});

it('syncs the email thread aggregate when an outbound reply is sent', function (): void {
    $original = Email::query()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
        'rfc_message_id' => '<original@example.com>',
        'provider_message_id' => 'provider-original',
        'thread_id' => 'thread-reply-1',
        'subject' => 'Original Subject',
        'snippet' => 'Original',
        'sent_at' => now()->subHour(),
        'direction' => EmailDirection::INBOUND,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'status' => EmailStatus::SENT,
    ]);
    $original->participants()->create([
        'email_address' => 'recipient@example.com',
        'name' => 'Recipient',
        'role' => 'from',
    ]);

    $reply = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Re: Original Subject',
        'body_html' => '<p>Reply</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => 'Recipient']],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => $original->getKey(),
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('sendMessage')->once()->andReturn([
        'provider_message_id' => 'provider-reply',
        'thread_id' => 'thread-reply-1',
        'rfc_message_id' => '<reply@example.com>',
    ]);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    app(EmailSendingService::class)->send($reply);

    $thread = EmailThread::query()
        ->where('connected_account_id', $this->account->getKey())
        ->where('thread_id', 'thread-reply-1')
        ->first();

    expect($thread)->not->toBeNull()
        ->and($thread->email_count)->toBe(2)
        ->and($thread->last_email_at->greaterThan($thread->first_email_at))->toBeTrue();
});

it('links the queued email to a CRM record via emailables', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
    ]);

    $sendData = [
        'connected_account_id' => $this->account->id,
        'subject' => 'Hello',
        'body_html' => '<p>Hi</p>',
        'to' => [['email' => 'jane@example.com', 'name' => 'Jane']],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ];

    $email = app(SendEmailAction::class)->execute($sendData, People::class, $person->id);

    $this->assertDatabaseHas('emailables', [
        'email_id' => $email->getKey(),
        'emailable_type' => People::class,
        'emailable_id' => $person->id,
        'link_source' => 'manual',
    ]);
});

it('rejects sending through a connected account owned by another user', function (): void {
    $otherUser = User::factory()->withTeam()->create();

    $foreignAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $otherUser->currentTeam->id,
        'user_id' => $otherUser->id,
        'email_address' => 'victim@example.com',
    ]));

    expect(fn () => app(SendEmailAction::class)->execute([
        'connected_account_id' => $foreignAccount->id,
        'subject' => 'Impersonation attempt',
        'body_html' => '<p>nope</p>',
        'to' => [['email' => 'x@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]))->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseMissing('emails', ['subject' => 'Impersonation attempt']);
});

it('throws when the user has hit the max queued limit', function (): void {
    config(['email-integration.outbox.max_queued_per_user' => 1]);

    Email::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Already queued',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);

    expect(fn () => app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Over limit',
        'body_html' => '<p>test</p>',
        'to' => [['email' => 'x@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]))->toThrow(RuntimeException::class, 'queued');
});
