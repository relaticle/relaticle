<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailThread;

mutates(StoreEmailAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
});

function makeFetchedEmailData(array $overrides = []): FetchedEmailData
{
    return new FetchedEmailData(
        providerMessageId: $overrides['providerMessageId'] ?? 'msg-001',
        rfcMessageId: $overrides['rfcMessageId'] ?? '<msg-001@example.com>',
        threadId: $overrides['threadId'] ?? 'thread-001',
        inReplyTo: $overrides['inReplyTo'] ?? null,
        subject: $overrides['subject'] ?? 'Test Subject',
        snippet: $overrides['snippet'] ?? 'Test snippet',
        sentAt: $overrides['sentAt'] ?? now(),
        direction: $overrides['direction'] ?? EmailDirection::INBOUND,
        folder: $overrides['folder'] ?? EmailFolder::Inbox,
        hasAttachments: $overrides['hasAttachments'] ?? false,
        isRead: $overrides['isRead'] ?? false,
        bodyText: $overrides['bodyText'] ?? 'Plain text body',
        bodyHtml: $overrides['bodyHtml'] ?? '<p>HTML body</p>',
        participants: $overrides['participants'] ?? [
            ['email_address' => 'sender@external.com', 'name' => 'Sender', 'role' => 'from'],
            ['email_address' => 'owner@example.com', 'name' => 'Owner', 'role' => 'to'],
        ],
        attachments: $overrides['attachments'] ?? [],
        providerCategory: $overrides['providerCategory'] ?? null,
    );
}

it('persists the email record with correct fields', function (): void {
    $sentAt = now()->subHour();
    $data = makeFetchedEmailData([
        'providerMessageId' => 'gmail-abc123',
        'rfcMessageId' => '<unique@example.com>',
        'threadId' => 'thread-xyz',
        'subject' => 'Hello World',
        'snippet' => 'First 255 chars...',
        'sentAt' => $sentAt,
        'direction' => EmailDirection::INBOUND,
        'folder' => EmailFolder::Inbox,
        'hasAttachments' => false,
        'isRead' => false,
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    expect($email)->toBeInstanceOf(Email::class)
        ->and($email->team_id)->toBe($this->team->id)
        ->and($email->user_id)->toBe($this->user->id)
        ->and($email->connected_account_id)->toBe($this->account->getKey())
        ->and($email->provider_message_id)->toBe('gmail-abc123')
        ->and($email->rfc_message_id)->toBe('<unique@example.com>')
        ->and($email->thread_id)->toBe('thread-xyz')
        ->and($email->subject)->toBe('Hello World')
        ->and($email->snippet)->toBe('First 255 chars...')
        ->and($email->direction)->toBe(EmailDirection::INBOUND)
        ->and($email->folder)->toBe(EmailFolder::Inbox)
        ->and($email->has_attachments)->toBeFalse();

    $this->assertDatabaseMissing('email_reads', [
        'email_id' => $email->getKey(),
        'user_id' => $this->user->id,
    ]);
});

it('stores a single system category label derived by rules', function (): void {
    $email = resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData());

    expect($email->labels()->where('source', 'system')->count())->toBe(1);
});

it("trusts the provider's native category when one is supplied", function (): void {
    $data = makeFetchedEmailData(['providerCategory' => EmailCategory::Marketing]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    $this->assertDatabaseHas('email_labels', [
        'email_id' => $email->getKey(),
        'label' => EmailCategory::Marketing->value,
        'source' => 'system',
    ]);
});

it('classifies a calendar invite as Scheduling from the .ics part', function (): void {
    $data = makeFetchedEmailData([
        'subject' => 'Project kickoff',
        'attachments' => [[
            'filename' => 'invite.ics',
            'mime_type' => 'text/calendar',
            'size' => 1024,
            'content_id' => null,
            'attachment_id' => null,
            'inline_data' => null,
        ]],
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    $this->assertDatabaseHas('email_labels', [
        'email_id' => $email->getKey(),
        'label' => EmailCategory::Scheduling->value,
        'source' => 'system',
    ]);
});

it('classifies a billing sender as Invoice', function (): void {
    $data = makeFetchedEmailData([
        'subject' => 'Your monthly statement',
        'participants' => [
            ['email_address' => 'billing@vendor.com', 'name' => 'Vendor', 'role' => 'from'],
            ['email_address' => 'owner@example.com', 'name' => 'Owner', 'role' => 'to'],
        ],
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    $this->assertDatabaseHas('email_labels', [
        'email_id' => $email->getKey(),
        'label' => EmailCategory::Invoice->value,
        'source' => 'system',
    ]);
});

it('falls back to Other for unrecognised external mail', function (): void {
    $data = makeFetchedEmailData([
        'subject' => 'hey there',
        'participants' => [
            ['email_address' => 'jane@external.com', 'name' => 'Jane', 'role' => 'from'],
            ['email_address' => 'owner@example.com', 'name' => 'Owner', 'role' => 'to'],
        ],
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    $this->assertDatabaseHas('email_labels', [
        'email_id' => $email->getKey(),
        'label' => EmailCategory::Other->value,
        'source' => 'system',
    ]);
});

it("records the owner's read state when isRead is true", function (): void {
    $sentAt = now()->subHour();
    $data = makeFetchedEmailData(['sentAt' => $sentAt, 'isRead' => true]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    $read = $email->reads()->where('user_id', $this->user->id)->sole();

    expect($read->read_at->toDateTimeString())->toBe($sentAt->toDateTimeString());
});

it('stores body_text and body_html in email_bodies', function (): void {
    $data = makeFetchedEmailData([
        'bodyText' => 'Plain text content',
        'bodyHtml' => '<p>Rich <b>HTML</b> content</p>',
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    expect($email->body)->not->toBeNull()
        ->and($email->body->body_text)->toBe('Plain text content')
        ->and($email->body->body_html)->toBe('<p>Rich <b>HTML</b> content</p>');
});

it('creates email participants', function (): void {
    $data = makeFetchedEmailData([
        'participants' => [
            ['email_address' => 'alice@acme.com', 'name' => 'Alice', 'role' => 'from'],
            ['email_address' => 'bob@acme.com', 'name' => 'Bob', 'role' => 'to'],
            ['email_address' => 'carol@acme.com', 'name' => null, 'role' => 'cc'],
        ],
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    expect($email->participants)->toHaveCount(3);

    $addresses = $email->participants->pluck('email_address')->sort()->values()->toArray();
    expect($addresses)->toBe(['alice@acme.com', 'bob@acme.com', 'carol@acme.com']);
});

it('creates email attachments', function (): void {
    $data = makeFetchedEmailData([
        'hasAttachments' => true,
        'attachments' => [
            [
                'filename' => 'invoice.pdf',
                'mime_type' => 'application/pdf',
                'size' => 204800,
                'content_id' => null,
                'attachment_id' => 'att-001',
                'inline_data' => null,
            ],
        ],
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    expect($email->attachments)->toHaveCount(1);

    $attachment = $email->attachments->first();
    expect($attachment->filename)->toBe('invoice.pdf')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->size)->toBe(204800)
        ->and($attachment->provider_attachment_id)->toBe('att-001');
});

it('marks email as internal when all participants are team members', function (): void {
    $teamMember = User::factory()->create();
    $this->team->users()->attach($teamMember, ['role' => 'editor']);

    $data = makeFetchedEmailData([
        'participants' => [
            ['email_address' => $this->user->email, 'name' => 'Owner', 'role' => 'from'],
            ['email_address' => $teamMember->email, 'name' => 'Team Member', 'role' => 'to'],
        ],
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    expect($email->is_internal)->toBeTrue();
});

it('treats a member as internal even when their active team is a different team', function (): void {
    // Membership in THIS team, but their current_team_id points at another team.
    // Keying internal-detection off current_team_id (instead of membership) would
    // wrongly classify the email as external and leak it to other members.
    $otherTeamMember = User::factory()->withTeam()->create();
    $this->team->users()->attach($otherTeamMember, ['role' => 'editor']);

    expect($otherTeamMember->current_team_id)->not->toBe($this->team->id);

    $data = makeFetchedEmailData([
        'participants' => [
            ['email_address' => $this->user->email, 'name' => 'Owner', 'role' => 'from'],
            ['email_address' => $otherTeamMember->email, 'name' => 'Member', 'role' => 'to'],
        ],
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    expect($email->is_internal)->toBeTrue();
});

it('does not mark email as internal when at least one external participant exists', function (): void {
    $data = makeFetchedEmailData([
        'participants' => [
            ['email_address' => $this->user->email, 'name' => 'Owner', 'role' => 'from'],
            ['email_address' => 'external@partner.com', 'name' => 'Partner', 'role' => 'to'],
        ],
    ]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    expect($email->is_internal)->toBeFalse();
});

it('stores the email in the database', function (): void {
    $data = makeFetchedEmailData(['providerMessageId' => 'stored-msg-001']);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    $this->assertDatabaseHas('emails', [
        'id' => $email->getKey(),
        'provider_message_id' => 'stored-msg-001',
        'team_id' => $this->team->id,
    ]);
});

it('runs CRM linking exactly once (no double email_count bump)', function (): void {
    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if (! $emailField) {
        $this->markTestSkipped('No emails custom field seeded for this team.');
    }

    $person = People::query()->create([
        'team_id' => $this->team->id,
        'name' => 'Counted Person',
        'creator_id' => $this->user->id,
        'email_count' => 0,
    ]);

    $person->saveCustomFieldValue($emailField, ['counted@partner.com'], $this->team);

    $data = makeFetchedEmailData([
        'direction' => EmailDirection::INBOUND,
        'participants' => [
            ['email_address' => 'counted@partner.com', 'name' => 'Counted Person', 'role' => 'from'],
            ['email_address' => $this->user->email, 'name' => 'Owner', 'role' => 'to'],
        ],
    ]);

    resolve(StoreEmailAction::class)->execute($this->account, $data);

    $fresh = $person->fresh();

    expect($fresh->email_count)->toBe(1)
        ->and($fresh->inbound_email_count)->toBe(1)
        ->and($fresh->outbound_email_count)->toBe(0);
});

it('creates the email thread aggregate on first email', function (): void {
    $sentAt = now()->subHour();
    $data = makeFetchedEmailData([
        'threadId' => 'thread-agg-1',
        'subject' => 'Thread Subject',
        'sentAt' => $sentAt,
        'participants' => [
            ['email_address' => 'a@external.com', 'name' => 'A', 'role' => 'from'],
            ['email_address' => 'owner@example.com', 'name' => 'Owner', 'role' => 'to'],
        ],
    ]);

    resolve(StoreEmailAction::class)->execute($this->account, $data);

    $thread = EmailThread::query()
        ->where('connected_account_id', $this->account->getKey())
        ->where('thread_id', 'thread-agg-1')
        ->first();

    expect($thread)->not->toBeNull()
        ->and($thread->team_id)->toBe($this->team->id)
        ->and($thread->subject)->toBe('Thread Subject')
        ->and($thread->email_count)->toBe(1)
        ->and($thread->participant_count)->toBe(2)
        ->and($thread->first_email_at->toDateTimeString())->toBe($sentAt->toDateTimeString())
        ->and($thread->last_email_at->toDateTimeString())->toBe($sentAt->toDateTimeString());
});

it('refreshes the existing thread aggregate as later emails arrive', function (): void {
    $first = now()->subHours(2);
    $second = now()->subHour();

    resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'agg-msg-1',
        'rfcMessageId' => '<agg-1@example.com>',
        'threadId' => 'thread-agg-2',
        'subject' => 'Original Subject',
        'sentAt' => $first,
        'participants' => [
            ['email_address' => 'a@external.com', 'name' => 'A', 'role' => 'from'],
            ['email_address' => 'owner@example.com', 'name' => 'Owner', 'role' => 'to'],
        ],
    ]));

    resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'providerMessageId' => 'agg-msg-2',
        'rfcMessageId' => '<agg-2@example.com>',
        'threadId' => 'thread-agg-2',
        'subject' => 'Reply Subject',
        'sentAt' => $second,
        'participants' => [
            ['email_address' => 'owner@example.com', 'name' => 'Owner', 'role' => 'from'],
            ['email_address' => 'b@external.com', 'name' => 'B', 'role' => 'to'],
        ],
    ]));

    $threads = EmailThread::query()
        ->where('connected_account_id', $this->account->getKey())
        ->where('thread_id', 'thread-agg-2')
        ->get();

    expect($threads)->toHaveCount(1);

    $thread = $threads->first();
    expect($thread->email_count)->toBe(2)
        ->and($thread->participant_count)->toBe(3)
        ->and($thread->subject)->toBe('Original Subject')
        ->and($thread->first_email_at->toDateTimeString())->toBe($first->toDateTimeString())
        ->and($thread->last_email_at->toDateTimeString())->toBe($second->toDateTimeString());
});

it('excludes queued unsent emails from the thread aggregate', function (): void {
    $sentAt = now()->subHour();

    // A queued outbound reply already sits in the thread with no sent_at yet.
    Email::query()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
        'rfc_message_id' => null,
        'provider_message_id' => null,
        'thread_id' => 'thread-queued-1',
        'subject' => 'Queued Reply',
        'snippet' => 'Queued',
        'sent_at' => null,
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
    ]);

    resolve(StoreEmailAction::class)->execute($this->account, makeFetchedEmailData([
        'threadId' => 'thread-queued-1',
        'subject' => 'Inbound Subject',
        'sentAt' => $sentAt,
    ]));

    $thread = EmailThread::query()
        ->where('connected_account_id', $this->account->getKey())
        ->where('thread_id', 'thread-queued-1')
        ->first();

    expect($thread)->not->toBeNull()
        ->and($thread->email_count)->toBe(1)
        ->and($thread->last_email_at?->toDateTimeString())->toBe($sentAt->toDateTimeString())
        ->and($thread->first_email_at?->toDateTimeString())->toBe($sentAt->toDateTimeString());
});

it('stores body in email_bodies table', function (): void {
    $data = makeFetchedEmailData();

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    $this->assertDatabaseHas('email_bodies', [
        'email_id' => $email->getKey(),
        'body_text' => 'Plain text body',
    ]);
});
