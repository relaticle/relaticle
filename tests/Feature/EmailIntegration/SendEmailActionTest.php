<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\EmailInlineImageEmbedder;
use Relaticle\EmailIntegration\Services\EmailSendingService;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(SendEmailAction::class, LinkEmailAction::class, EmailSendingService::class, ConnectedAccount::class, EmailInlineImageEmbedder::class, EmailAttachment::class);

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

it('forbids queuing mail when the mailbox cannot send', function (): void {
    $this->account->update([
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    expect(fn () => app(SendEmailAction::class)->execute([
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
    ]))->toThrow(HttpException::class);
});

it('forbids queuing mail when the mailbox is not active', function (EmailAccountStatus $status): void {
    $this->account->update(['status' => $status]);

    expect(fn () => app(SendEmailAction::class)->execute([
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
    ]))->toThrow(HttpException::class);
})->with([
    'error' => EmailAccountStatus::ERROR,
    'reauth required' => EmailAccountStatus::REAUTH_REQUIRED,
]);

it('ignores an in_reply_to_email_id that belongs to another team', function (): void {
    // An email owned by a different tenant, with its own active connected account so
    // it is not filtered out by the ActiveAccountScope — only the team_id scope on
    // the reply lookup should exclude it.
    $otherUser = User::factory()->withTeam()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $otherUser->currentTeam->getKey(),
        'user_id' => $otherUser->getKey(),
    ]));
    $foreignEmail = Email::query()->create([
        'team_id' => $otherUser->currentTeam->getKey(),
        'user_id' => $otherUser->getKey(),
        'connected_account_id' => $otherAccount->getKey(),
        'rfc_message_id' => '<secret@other-team.com>',
        'provider_message_id' => 'provider-foreign',
        'thread_id' => 'foreign-thread-id',
        'subject' => 'Confidential',
        'snippet' => 'secret',
        'sent_at' => now()->subHour(),
        'direction' => EmailDirection::INBOUND,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'status' => EmailStatus::SENT,
    ]);

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Re: nothing',
        'body_html' => '<p>Reply</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => 'Recipient']],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => $foreignEmail->getKey(),
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]);

    // The foreign thread_id / rfc_message_id must not leak into this tenant's email.
    expect($email->thread_id)->toBeNull()
        ->and($email->in_reply_to)->toBeNull();
});

it('does not copy a provider thread id from a different sending mailbox', function (): void {
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'other@example.com',
        'display_name' => 'Other Mailbox',
    ]));
    $sharedEmail = Email::query()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $otherAccount->getKey(),
        'rfc_message_id' => '<shared@example.com>',
        'provider_message_id' => 'provider-shared',
        'thread_id' => 'gmail-thread-from-other-mailbox',
        'subject' => 'Shared mail',
        'snippet' => 'Shared',
        'sent_at' => now()->subHour(),
        'direction' => EmailDirection::INBOUND,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'status' => EmailStatus::SENT,
    ]);

    $reply = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Re: Shared mail',
        'body_html' => '<p>Reply from my mailbox</p>',
        'to' => [['email' => 'someone@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => $sharedEmail->getKey(),
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]);

    expect($reply->thread_id)->toBeNull()
        ->and($reply->in_reply_to)->toBe('<shared@example.com>');

    $captured = null;
    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('sendMessage')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
        $captured = $payload;

        return [
            'provider_message_id' => 'provider-cross-mailbox',
            'thread_id' => 'new-thread-in-sender-mailbox',
            'rfc_message_id' => $payload['rfc_message_id'] ?? '<reply@example.com>',
        ];
    });
    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    app(EmailSendingService::class)->send($reply);

    expect($captured)->not->toHaveKey('thread_id')
        ->and($captured['in_reply_to'])->toBe('<shared@example.com>');
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
        'emailable_type' => $person->getMorphClass(),
        'emailable_id' => $person->id,
        'link_source' => 'manual',
    ]);

    expect($person->emails()->whereKey($email->getKey())->exists())->toBeTrue();
});

it('updates record metrics after a manually linked queued send is delivered', function (): void {
    $this->account->update([
        'email_address' => 'sender@acmecorp.com',
        'display_name' => 'Test Sender',
    ]);

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
        'email_count' => 0,
        'outbound_email_count' => 0,
    ]);

    $sendData = [
        'connected_account_id' => $this->account->id,
        'subject' => 'Hello',
        'body_html' => '<p>Hi</p>',
        'to' => [['email' => 'jane@clientcorp.com', 'name' => 'Jane']],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ];

    $email = app(SendEmailAction::class)->execute($sendData, People::class, $person->id);

    expect($person->fresh()->email_count)->toBe(0);

    $sentAt = now()->subHour();
    $email->update([
        'status' => EmailStatus::SENT,
        'sent_at' => $sentAt,
    ]);

    app(LinkEmailAction::class)->execute($email->fresh());

    $person->refresh();

    expect($person->email_count)->toBe(1)
        ->and($person->outbound_email_count)->toBe(1)
        ->and($person->last_email_at?->timestamp)->toBe($sentAt->timestamp)
        ->and($person->last_interaction_at?->timestamp)->toBe($sentAt->timestamp);
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

it('persists uploaded attachments and flags the email', function (): void {
    Storage::fake('local');

    $path = UploadedFile::fake()
        ->createWithContent('quarterly-report.pdf', '%PDF-1.4 fake pdf bytes')
        ->store('email-attachments', 'local');

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Here is the report',
        'body_html' => '<p>See attached.</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
        'attachments' => [$path],
        'attachment_file_names' => [$path => 'quarterly-report.pdf'],
    ]);

    $attachment = $email->attachments()->first();

    expect($email->has_attachments)->toBeTrue()
        ->and($email->attachments()->count())->toBe(1)
        ->and($attachment->filename)->toBe('quarterly-report.pdf')
        ->and($attachment->storage_path)->toBe($path)
        ->and($attachment->size)->toBeGreaterThan(0)
        ->and($attachment->mime_type)->not->toBeEmpty();
});

it('reads attachment bytes into the provider payload when sending', function (): void {
    Storage::fake('local');

    $path = UploadedFile::fake()
        ->createWithContent('notes.txt', 'attachment body')
        ->store('email-attachments', 'local');

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'With file',
        'body_html' => '<p>hi</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
        'attachments' => [$path],
        'attachment_file_names' => [$path => 'notes.txt'],
    ]);

    $captured = null;
    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('sendMessage')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
        $captured = $payload;

        return [
            'provider_message_id' => 'pm',
            'thread_id' => 'th',
            'rfc_message_id' => $payload['rfc_message_id'] ?? '<x@example.com>',
        ];
    });
    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    app(EmailSendingService::class)->send($email->refresh());

    expect($captured['attachments'])->toHaveCount(1)
        ->and($captured['attachments'][0]['filename'])->toBe('notes.txt')
        ->and($captured['attachments'][0]['mime_type'])->not->toBeEmpty()
        ->and($captured['attachments'][0]['content'])->toBe('attachment body');
});

it('persists inline cid attachments and includes them in the provider payload', function (): void {
    Storage::fake('local');

    $path = UploadedFile::fake()
        ->createWithContent('logo.png', 'png-bytes')
        ->store('email-attachments', 'local');

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Fwd with logo',
        'body_html' => '<p><img src="cid:logo@example.test"></p>',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::FORWARD,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
        'attachments' => [$path],
        'attachment_file_names' => [$path => 'logo.png'],
        'attachment_attributes' => [$path => [
            'is_inline' => true,
            'content_id' => 'logo@example.test',
        ]],
    ]);

    $attachment = $email->attachments()->first();

    expect($email->has_attachments)->toBeFalse()
        ->and($attachment->is_inline)->toBeTrue()
        ->and($attachment->content_id)->toBe('logo@example.test');

    $captured = null;
    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('sendMessage')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
        $captured = $payload;

        return [
            'provider_message_id' => 'pm',
            'thread_id' => 'th',
            'rfc_message_id' => $payload['rfc_message_id'] ?? '<x@example.com>',
        ];
    });
    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    app(EmailSendingService::class)->send($email->refresh());

    expect($captured['attachments'])->toHaveCount(1)
        ->and($captured['attachments'][0]['is_inline'])->toBeTrue()
        ->and($captured['attachments'][0]['content_id'])->toBe('logo@example.test')
        ->and($captured['attachments'][0]['content'])->toBe('png-bytes');
});

it('embeds rich editor inline images from data-id paths when queuing send', function (): void {
    Storage::fake('local');
    Storage::fake('public');

    $editorPath = EmailAttachment::composeImagesDirectory((string) $this->user->current_team_id).'/editor-image.png';
    Storage::disk('local')->put($editorPath, 'png-bytes');

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Inline image',
        'body_html' => '<p>See below</p><img data-id="'.$editorPath.'" src="">',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]);

    $attachment = $email->attachments()->first();

    expect($email->attachments)->toHaveCount(1)
        ->and($attachment->is_inline)->toBeTrue()
        ->and($attachment->content_id)->not->toBeEmpty()
        ->and($email->body?->body_html)->toContain('cid:'.$attachment->content_id)
        ->and($email->body?->body_html)->not->toContain('data-id');

    $sanitized = EmailHtmlSanitizer::sanitize($email->body?->body_html, $email->inlineAttachments());

    expect($sanitized)
        ->toContain(route('email-attachments.inline', ['attachment' => $attachment->getKey()]))
        ->not->toContain('cid:'.$attachment->content_id);
});

it('does not attach a storage file referenced by a composer image url', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Storage::disk('public')->put('private.csv', 'secret,tenant,data');
    Storage::disk('local')->put('private.csv', 'secret,tenant,data');

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Inline image',
        'body_html' => '<p>See below</p><img src="/storage/private.csv">',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]);

    expect($email->attachments)->toHaveCount(0)
        ->and($email->body?->body_html)->toContain('/storage/private.csv')
        ->and($email->body?->body_html)->not->toContain('cid:');
});

it('does not attach another tenant image named in composer html', function (): void {
    Storage::fake('local');

    $otherUser = User::factory()->withTeam()->create();
    $foreignPath = EmailAttachment::composeImagesDirectory((string) $otherUser->current_team_id).'/secret.png';
    Storage::disk('local')->put($foreignPath, 'png-bytes-from-other-tenant');

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Inline image',
        'body_html' => '<p>See below</p><img data-id="'.$foreignPath.'" src="">',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]);

    expect($email->attachments)->toHaveCount(0)
        ->and($email->body?->body_html)->toContain($foreignPath)
        ->and(Storage::disk('local')->get($foreignPath))->toBe('png-bytes-from-other-tenant');
});

it('does not attach a non-image file from the tenant compose directory', function (): void {
    Storage::fake('local');

    $path = EmailAttachment::composeImagesDirectory((string) $this->user->current_team_id).'/notes.csv';
    Storage::disk('local')->put($path, 'secret,csv,contents');

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Inline image',
        'body_html' => '<p>See below</p><img data-id="'.$path.'" src="">',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]);

    expect($email->attachments)->toHaveCount(0)
        ->and($email->body?->body_html)->toContain($path);
});

it('does not follow path traversal in composer image data-id', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('private.csv', 'secret,tenant,data');

    $path = EmailAttachment::composeImagesDirectory((string) $this->user->current_team_id).'/../../private.csv';

    $email = app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Inline image',
        'body_html' => '<p>See below</p><img data-id="'.$path.'" src="">',
        'to' => [['email' => 'recipient@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]);

    expect($email->attachments)->toHaveCount(0)
        ->and(Storage::disk('local')->get('private.csv'))->toBe('secret,tenant,data');
});
