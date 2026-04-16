<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Jobs\SendEmailJob;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;

mutates(EmailsRelationManager::class);

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
        'email_address' => 'me@example.com',
        'display_name' => 'Me',
        'access_token' => 'fake-token',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]);

    $this->person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
    ]);

    $this->inboundEmail = Email::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Original Subject',
        'sent_at' => now()->subHours(2),
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::SYNC,
        'rfc_message_id' => '<original@example.com>',
        'thread_id' => 'thread-abc',
    ]);

    EmailBody::create([
        'email_id' => $this->inboundEmail->id,
        'body_html' => '<p>Original body</p>',
        'body_text' => 'Original body',
    ]);

    EmailParticipant::create([
        'email_id' => $this->inboundEmail->id,
        'email_address' => 'sender@contact.com',
        'name' => 'Original Sender',
        'role' => EmailParticipantRole::FROM,
    ]);

    EmailParticipant::create([
        'email_id' => $this->inboundEmail->id,
        'email_address' => 'cc-person@contact.com',
        'name' => 'CC Person',
        'role' => EmailParticipantRole::CC,
    ]);
});

it('reply dispatches SendEmailJob with REPLY creation_source', function (): void {
    Queue::fake();

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction(
            'replyForwardEmail',
            data: [
                'connected_account_id' => $this->account->id,
                'to' => ['sender@contact.com'],
                'cc' => [],
                'bcc' => [],
                'subject' => 'Re: Original Subject',
                'body_html' => '<p>Reply body</p>',
                'in_reply_to_email_id' => $this->inboundEmail->id,
            ],
            arguments: ['emailId' => $this->inboundEmail->id, 'mode' => 'reply'],
        )
        ->assertNotified('Email queued');

    Queue::assertPushed(
        SendEmailJob::class,
        fn (SendEmailJob $job): bool => $job->emailData['creation_source'] === EmailCreationSource::REPLY
            && $job->emailData['in_reply_to_email_id'] === $this->inboundEmail->id
    );
});

it('forward dispatches SendEmailJob with FORWARD creation_source', function (): void {
    Queue::fake();

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction(
            'replyForwardEmail',
            data: [
                'connected_account_id' => $this->account->id,
                'to' => ['forward-to@example.com'],
                'cc' => [],
                'bcc' => [],
                'subject' => 'Fwd: Original Subject',
                'body_html' => '<p>Forwarded</p>',
            ],
            arguments: ['emailId' => $this->inboundEmail->id, 'mode' => 'forward'],
        );

    Queue::assertPushed(
        SendEmailJob::class,
        fn (SendEmailJob $job): bool => $job->emailData['creation_source'] === EmailCreationSource::FORWARD
            && $job->emailData['in_reply_to_email_id'] === null
    );
});

it('reply_all dispatches SendEmailJob with REPLY_ALL creation_source', function (): void {
    Queue::fake();

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction(
            'replyForwardEmail',
            data: [
                'connected_account_id' => $this->account->id,
                'to' => ['sender@contact.com', 'cc-person@contact.com'],
                'cc' => [],
                'bcc' => [],
                'subject' => 'Re: Original Subject',
                'body_html' => '<p>Reply all body</p>',
                'in_reply_to_email_id' => $this->inboundEmail->id,
            ],
            arguments: ['emailId' => $this->inboundEmail->id, 'mode' => 'reply_all'],
        );

    Queue::assertPushed(
        SendEmailJob::class,
        fn (SendEmailJob $job): bool => $job->emailData['creation_source'] === EmailCreationSource::REPLY_ALL
    );
});
