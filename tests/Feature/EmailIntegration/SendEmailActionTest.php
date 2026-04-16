<?php

declare(strict_types=1);

use App\Jobs\SendEmailJob;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\EmailSendingService;

mutates(SendEmailAction::class, EmailSendingService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'test-account-id',
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
        'access_token' => 'fake-token',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]);
});

test('SendEmailAction dispatches SendEmailJob to the emails queue', function (): void {
    Queue::fake();

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

    app(SendEmailAction::class)->execute($sendData);

    Queue::assertPushedOn('emails', SendEmailJob::class);
    Queue::assertPushed(
        SendEmailJob::class,
        fn (SendEmailJob $job): bool => $job->accountId === $this->account->id && $job->batchId === null
    );
});

test('SendEmailAction passes linkToType and linkToId to the job', function (): void {
    Queue::fake();

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

    app(SendEmailAction::class)->execute($sendData, People::class, $person->id);

    Queue::assertPushed(
        SendEmailJob::class,
        fn (SendEmailJob $job): bool => $job->linkToType === People::class
            && $job->linkToId === $person->id
    );
});

test('EmailSendingService throws when hourly send limit is exceeded', function (): void {
    $this->account->update(['hourly_send_limit' => 2]);

    foreach (range(1, 2) as $i) {
        Email::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'connected_account_id' => $this->account->id,
            'subject' => "Email {$i}",
            'sent_at' => now()->subMinutes(10),
            'direction' => EmailDirection::OUTBOUND,
            'status' => EmailStatus::SENT,
            'privacy_tier' => EmailPrivacyTier::FULL,
        ]);
    }

    expect(fn () => app(EmailSendingService::class)->send($this->account, [
        'subject' => 'Over limit',
        'body_html' => '<p>test</p>',
        'to' => [['email' => 'x@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]))->toThrow(RuntimeException::class, 'Hourly send limit');
});

test('EmailSendingService throws when daily send limit is exceeded', function (): void {
    $this->account->update(['daily_send_limit' => 1]);

    Email::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Earlier email',
        'sent_at' => now()->subHours(2),
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENT,
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);

    expect(fn () => app(EmailSendingService::class)->send($this->account, [
        'subject' => 'Over limit',
        'body_html' => '<p>test</p>',
        'to' => [['email' => 'x@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]))->toThrow(RuntimeException::class, 'Daily send limit');
});
