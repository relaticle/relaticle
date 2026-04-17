<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
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

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'me@example.com',
        'display_name' => 'Me',
    ]));

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

it('reply persists a queued Email with REPLY creation_source', function (): void {
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

    $reply = Email::query()
        ->where('direction', EmailDirection::OUTBOUND)
        ->where('creation_source', EmailCreationSource::REPLY)
        ->firstOrFail();

    expect($reply->status)->toBe(EmailStatus::QUEUED)
        ->and($reply->thread_id)->toBe($this->inboundEmail->thread_id)
        ->and($reply->in_reply_to)->toBe($this->inboundEmail->rfc_message_id);
});

it('forward persists a queued Email with FORWARD creation_source', function (): void {
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

    $forward = Email::query()
        ->where('direction', EmailDirection::OUTBOUND)
        ->where('creation_source', EmailCreationSource::FORWARD)
        ->firstOrFail();

    expect($forward->status)->toBe(EmailStatus::QUEUED)
        ->and($forward->in_reply_to)->toBeNull();
});

it('reply_all persists a queued Email with REPLY_ALL creation_source', function (): void {
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

    expect(Email::query()
        ->where('direction', EmailDirection::OUTBOUND)
        ->where('creation_source', EmailCreationSource::REPLY_ALL)
        ->exists())->toBeTrue();
});
