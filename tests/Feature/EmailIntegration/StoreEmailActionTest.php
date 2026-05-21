<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

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
        ->and($email->has_attachments)->toBeFalse()
        ->and($email->read_at)->toBeNull();
});

it('sets read_at when isRead is true', function (): void {
    $sentAt = now()->subHour();
    $data = makeFetchedEmailData(['sentAt' => $sentAt, 'isRead' => true]);

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    expect($email->read_at)->not->toBeNull()
        ->and($email->read_at->toDateTimeString())->toBe($sentAt->toDateTimeString());
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
    $teamMember = User::factory()->create(['current_team_id' => $this->team->id]);

    $data = makeFetchedEmailData([
        'participants' => [
            ['email_address' => $this->user->email, 'name' => 'Owner', 'role' => 'from'],
            ['email_address' => $teamMember->email, 'name' => 'Team Member', 'role' => 'to'],
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

it('stores body in email_bodies table', function (): void {
    $data = makeFetchedEmailData();

    $email = resolve(StoreEmailAction::class)->execute($this->account, $data);

    $this->assertDatabaseHas('email_bodies', [
        'email_id' => $email->getKey(),
        'body_text' => 'Plain text body',
    ]);
});
