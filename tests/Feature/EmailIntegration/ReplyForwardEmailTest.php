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
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
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

it('renders an email body in a sandboxed iframe with sanitized content', function (): void {
    $this->inboundEmail->body->update([
        'body_html' => '<style>body{background:#fff}</style><p>Body</p><script>alert(1)</script>',
    ]);

    $page = livewire(EmailInboxPage::class)
        ->call('selectEmail', $this->inboundEmail->id)
        // The script tag is stripped by the sanitizer before it ever reaches the frame.
        ->assertDontSee('alert(1)', escape: false);

    // `allow-same-origin` is present so the page can measure the frame and size it to
    // its content. `allow-scripts` must NOT be — together they let a script inside the
    // frame strip its own sandbox, which is the whole reason the frame exists.
    $html = $page->html();
    $sandbox = str($html)->after('<iframe')->after('sandbox="')->before('"')->toString();

    expect($sandbox)
        ->toContain('allow-same-origin')
        ->not->toContain('allow-scripts');
});

it('reply_all recipients keep the original sender and drop the user\'s own address', function (): void {
    // The user's own address appears as a To recipient on the original — it must be
    // filtered out of a reply-all, while the original sender (FROM) must be kept.
    EmailParticipant::create([
        'email_id' => $this->inboundEmail->id,
        'email_address' => 'me@example.com',
        'name' => 'Me',
        'role' => EmailParticipantRole::TO,
    ]);

    $recipients = $this->inboundEmail->fresh('participants')->replyAllRecipients('me@example.com');

    expect($recipients)
        ->toContain('sender@contact.com')      // original sender is NOT dropped
        ->toContain('cc-person@contact.com')   // cc recipients included
        ->not->toContain('me@example.com');    // self excluded
});

it('inline composer prefills a reply from the selected email and queues it', function (): void {
    // The reply icons carry the target email, and the docked composer answers
    // `composer:reply` only — the floating window keeps Compose to itself.
    livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('openReply', $this->inboundEmail->id, 'reply')
        ->assertSet('isOpen', true)
        ->assertSet('replyMode', 'reply')
        ->assertSet('to', ['sender@contact.com'])
        ->assertSet('subject', 'Re: Original Subject')
        ->assertSet('inReplyToEmailId', $this->inboundEmail->id)
        ->set('bodyHtml', '<p>Reply via composer</p>')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('isOpen', false);

    $reply = Email::query()
        ->where('direction', EmailDirection::OUTBOUND)
        ->where('creation_source', EmailCreationSource::REPLY)
        ->firstOrFail();

    expect($reply->status)->toBe(EmailStatus::QUEUED)
        ->and($reply->in_reply_to)->toBe($this->inboundEmail->rfc_message_id);
});

it('inline composer prefills reply-all and forward from their modes', function (): void {
    $replyAll = livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('openReply', $this->inboundEmail->id, 'reply_all')
        ->assertSet('subject', 'Re: Original Subject');

    // Reply-all keeps the sender and the CC recipient, in whichever order.
    expect($replyAll->get('to'))
        ->toContain('sender@contact.com')
        ->toContain('cc-person@contact.com');

    livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('openReply', $this->inboundEmail->id, 'forward')
        ->assertSet('subject', 'Fwd: Original Subject')
        // A forward has no recipient yet, and does not thread against the original.
        ->assertSet('to', [])
        ->assertSet('inReplyToEmailId', null);
});

it('a reply saved as a draft still threads when it is sent later', function (): void {
    // The draft used to be stored with no link to the message it answered, so
    // reopening it resumed a plain new email and sending it threaded nowhere.
    $composer = livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('openReply', $this->inboundEmail->id, 'reply')
        ->set('bodyHtml', '<p>Started, not finished</p>')
        ->call('close');

    $draftId = Email::query()->where('status', EmailStatus::DRAFT)->sole()->getKey();

    livewire(EmailComposer::class)
        ->call('open', [], $draftId)
        ->assertSet('replyMode', 'reply')
        ->assertSet('inReplyToEmailId', $this->inboundEmail->id)
        ->call('send')
        ->assertHasNoErrors();

    $reply = Email::query()
        ->where('direction', EmailDirection::OUTBOUND)
        ->where('creation_source', EmailCreationSource::REPLY)
        ->sole();

    expect($reply->in_reply_to)->toBe($this->inboundEmail->rfc_message_id)
        ->and($reply->thread_id)->toBe($this->inboundEmail->thread_id);
});

it('a forward saved as a draft keeps its source without threading against it', function (): void {
    livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('openReply', $this->inboundEmail->id, 'forward')
        ->set('bodyHtml', '<p>Passing this on</p>')
        ->call('close');

    $draftId = Email::query()->where('status', EmailStatus::DRAFT)->sole()->getKey();

    livewire(EmailComposer::class)
        ->call('open', [], $draftId)
        ->assertSet('replyMode', 'forward')
        // The source is kept so the composer can show what is being forwarded...
        ->assertSet('sourceEmailId', $this->inboundEmail->id)
        // ...but a forward is a new message, so it must not thread against it.
        ->assertSet('inReplyToEmailId', null);
});

it('the docked composer closes when the reader moves to another email', function (): void {
    $other = Email::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Another Subject',
        'sent_at' => now()->subHour(),
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::SYNC,
        'rfc_message_id' => '<other@example.com>',
        'thread_id' => 'thread-def',
    ]);

    // Selecting a different email must tell the dock to stand down — a draft that
    // answers one message cannot stay docked under another.
    livewire(EmailInboxPage::class)
        ->set('selectedEmailId', $this->inboundEmail->id)
        ->call('selectEmail', $other->id)
        ->assertDispatched('composer:dismiss-inline');

    livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('openReply', $this->inboundEmail->id, 'reply')
        ->assertSet('isOpen', true)
        ->call('dismissInline')
        ->assertSet('isOpen', false);

    // The floating window is not dismissed by the reader moving on.
    livewire(EmailComposer::class)
        ->call('open')
        ->assertSet('isOpen', true)
        ->call('dismissInline')
        ->assertSet('isOpen', true);
});

it('the floating composer ignores reply events, and the docked one ignores compose', function (): void {
    livewire(EmailComposer::class)
        ->call('openReply', $this->inboundEmail->id, 'reply')
        ->assertSet('isOpen', false);

    livewire(EmailComposer::class, ['dock' => 'inline'])
        ->call('open')
        ->assertSet('isOpen', false);
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
