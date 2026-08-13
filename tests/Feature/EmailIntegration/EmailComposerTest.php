<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\DeleteEmailDraftAction;
use Relaticle\EmailIntegration\Actions\SaveEmailDraftAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;

use function Pest\Laravel\actingAs;

mutates(EmailComposer::class, SaveEmailDraftAction::class, DeleteEmailDraftAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->user->current_team_id,
        'status' => 'active',
    ]));
    actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->user->currentTeam);
});

it('opens via the composer:open event with the default account preselected', function (): void {
    Livewire::test(EmailComposer::class)
        ->assertSet('isOpen', false)
        ->dispatch('composer:open')
        ->assertSet('isOpen', true)
        ->assertSet('accountId', $this->account->id);
});

it('queues an email through SendEmailAction on send with the persisted body and undo-send window', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Quarterly sync')
        ->set('bodyHtml', '<p>Hello there</p>')
        ->call('send')
        ->assertSet('isOpen', false);

    $email = Email::query()->where('subject', 'Quarterly sync')->sole();
    expect($email->status)->toBe(EmailStatus::QUEUED)
        ->and($email->connected_account_id)->toBe($this->account->id)
        ->and($email->body->body_html)->toContain('Hello there')
        // Interactive sends must keep the priority queue's undo-send window
        // (EmailPriority::PRIORITY), not fall back to the bulk default.
        ->and($email->scheduled_for)->not->toBeNull();
});

it('includes the default signature content in the sent body_html', function (): void {
    EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'content_html' => '<p>Best, Test Sender</p>',
        'is_default' => true,
    ]));

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Signature roundtrip')
        ->call('send');

    $email = Email::query()->where('subject', 'Signature roundtrip')->sole();

    expect($email->body->body_html)->toContain('Best, Test Sender');
});

it('shows validation errors instead of sending when recipients are missing', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'No recipients')
        ->set('bodyHtml', '<p>Body</p>')
        ->call('send')
        ->assertHasErrors(['to'])
        ->assertSet('isOpen', true);

    expect(Email::query()->where('subject', 'No recipients')->exists())->toBeFalse();
});

it('surfaces a validation error for a malformed recipient and sends nothing', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['not-an-email'])
        ->set('subject', 'Malformed recipient')
        ->set('bodyHtml', '<p>Body</p>')
        ->call('send')
        ->assertHasErrors(['to.0'])
        ->assertSet('isOpen', true);

    expect(Email::query()->where('subject', 'Malformed recipient')->exists())->toBeFalse();
});

it('rejects an empty body with no signature block and sends nothing', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Empty body')
        ->call('send')
        ->assertHasErrors(['bodyHtml'])
        ->assertSet('isOpen', true);

    expect(Email::query()->where('subject', 'Empty body')->exists())->toBeFalse();
});

it('passes uploaded attachments through to the send action', function (): void {
    Storage::fake('local');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['a@example.com'])
        ->set('subject', 'With attachment')
        ->set('bodyHtml', '<p>see attached</p>')
        ->set('attachments', [UploadedFile::fake()->create('quote.pdf', 12)])
        ->call('send');

    $email = Email::query()->where('subject', 'With attachment')->sole();

    expect($email->has_attachments)->toBeTrue()
        ->and($email->attachments()->count())->toBe(1)
        ->and($email->attachments()->first()->filename)->toBe('quote.pdf');
});

it('does not render for users without an active connected account', function (): void {
    $this->account->update(['status' => EmailAccountStatus::DISCONNECTED]);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->assertSet('isOpen', false);
});

it('restores an already-open composer instead of resetting it when composer:open fires again', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'In progress')
        ->call('minimize')
        ->assertSet('isMinimized', true)
        ->dispatch('composer:open')
        ->assertSet('isOpen', true)
        ->assertSet('isMinimized', false)
        ->assertSet('subject', 'In progress');
});

it('does not leak another team\'s signature content via a foreign signatureId', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $otherUser->id,
        'team_id' => $otherUser->current_team_id,
        'status' => 'active',
    ]));
    $otherSignature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'content_html' => '<p>Confidential other-team signature</p>',
    ]));

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['victim@example.com'])
        ->set('subject', 'IDOR probe')
        ->set('bodyHtml', '<p>hello</p>')
        ->set('signatureId', $otherSignature->id)
        ->call('send');

    $email = Email::query()->where('subject', 'IDOR probe')->sole();

    expect($email->body->body_html)->not->toContain('Confidential other-team signature');
});

it('rejects a client-posted accountId that does not belong to the user', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $otherUser->id,
        'team_id' => $otherUser->current_team_id,
        'status' => 'active',
    ]));

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('accountId', $otherAccount->id)
        ->assertSet('accountId', null);
});

it('saves a draft when minimized and reopens it with state intact', function (): void {
    $component = Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['draft@example.com'])
        ->set('subject', 'Half-written')
        ->set('bodyHtml', '<p>wip</p>')
        ->call('minimize');

    $draft = Email::query()->where('status', EmailStatus::DRAFT)->sole();
    expect($draft->subject)->toBe('Half-written')
        ->and($draft->user_id)->toBe($this->user->id);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: $draft->id)
        ->assertSet('subject', 'Half-written')
        ->assertSet('to', ['draft@example.com'])
        ->assertSet('draftId', $draft->id);
});

it('deletes the draft after a successful send', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['x@example.com'])
        ->set('subject', 'From draft')
        ->set('bodyHtml', '<p>b</p>')
        ->call('minimize');

    $draft = Email::query()->where('status', EmailStatus::DRAFT)->sole();

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: $draft->id)
        ->call('send');

    // Email uses SoftDeletes — assert the row is actually gone (forceDelete()),
    // not merely soft-deleted, which a plain ->whereKey()->exists() would miss.
    expect(Email::query()->withTrashed()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(Email::query()->where('subject', 'From draft')->where('status', EmailStatus::QUEUED)->exists())->toBeTrue();
});

it('does not save empty drafts on close', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->call('close');

    expect(Email::query()->where('status', EmailStatus::DRAFT)->exists())->toBeFalse();
});

it('never lists drafts in the mail panes', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['d@example.com'])
        ->set('subject', 'Hidden draft')
        ->set('bodyHtml', '<p>b</p>')
        ->call('minimize');

    Livewire::test(EmailInboxPage::class)
        ->call('setFolder', 'all')
        ->assertDontSee('Hidden draft');
});

it('does not load another user\'s draft into the composer', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $otherUser->id,
        'team_id' => $otherUser->current_team_id,
        'status' => 'active',
    ]));

    actingAs($otherUser);
    Filament::setTenant($otherUser->currentTeam);

    $foreignDraft = Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['victim@example.com'])
        ->set('subject', 'Confidential draft')
        ->set('bodyHtml', '<p>secret</p>')
        ->call('minimize');

    $draft = Email::query()->where('status', EmailStatus::DRAFT)->where('subject', 'Confidential draft')->sole();
    expect($draft->connected_account_id)->toBe($otherAccount->id);

    actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: $draft->id)
        ->assertSet('draftId', null)
        ->assertSet('subject', null)
        ->assertSet('to', []);
});

it('closes and queues exactly once even when the draft row was already deleted before send completes', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['x@example.com'])
        ->set('subject', 'Racing draft')
        ->set('bodyHtml', '<p>b</p>')
        ->call('minimize');

    $draft = Email::query()->where('status', EmailStatus::DRAFT)->sole();

    $reopened = Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: $draft->id)
        ->assertSet('draftId', $draft->id);

    // Simulate a second tab on the same draft (or a retried request) having
    // already cleaned it up by the time this instance's send() runs.
    Email::query()->whereKey($draft->id)->forceDelete();

    $reopened->call('send')
        ->assertSet('isOpen', false);

    expect(Email::query()->where('status', EmailStatus::QUEUED)->where('subject', 'Racing draft')->count())->toBe(1);
});

it('does not load a draft that belongs to a different team of the same user', function (): void {
    $otherTeam = Team::factory()->create(['user_id' => $this->user->getKey()]);
    $this->user->teams()->attach($otherTeam, ['role' => 'admin']);

    $otherTeamAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $otherTeam->id,
        'status' => 'active',
    ]));

    $draft = Email::factory()->create([
        'team_id' => $otherTeam->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $otherTeamAccount->id,
        'status' => EmailStatus::DRAFT,
        'direction' => EmailDirection::OUTBOUND,
        'folder' => EmailFolder::Drafts,
        'sent_at' => null,
        'subject' => 'Other-team draft',
    ]);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: $draft->id)
        ->assertSet('draftId', null)
        ->assertSet('subject', null)
        ->assertSet('to', []);
});

it('excludes a teammate\'s unsent draft recipients from recipient suggestions', function (): void {
    $teammate = User::factory()->create(['current_team_id' => $this->user->current_team_id]);

    $teammateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $teammate->id,
        'team_id' => $this->user->current_team_id,
        'status' => 'active',
    ]));

    resolve(SaveEmailDraftAction::class)->execute(
        user: $teammate,
        data: [
            'connected_account_id' => $teammateAccount->id,
            'subject' => 'Teammate secret draft',
            'body_html' => '<p>hi</p>',
            'to' => ['hidden-recipient@example.com'],
            'cc' => [],
            'bcc' => [],
        ],
    );

    $suggestions = Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->instance()
        ->recipientSuggestions();

    expect($suggestions)->not->toContain('hidden-recipient@example.com');
});

it('saves pending attachments onto the draft when the composer is closed', function (): void {
    Storage::fake(EmailAttachment::DISK);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['x@example.com'])
        ->set('subject', 'Has an attachment')
        ->set('bodyHtml', '<p>b</p>')
        ->set('attachments', [UploadedFile::fake()->create('quote.pdf', 12)])
        ->call('close');

    $draft = Email::query()->where('status', EmailStatus::DRAFT)->where('subject', 'Has an attachment')->sole();

    expect($draft->has_attachments)->toBeTrue()
        ->and($draft->attachments)->toHaveCount(1)
        ->and($draft->attachments->first()->filename)->toBe('quote.pdf');

    Storage::disk(EmailAttachment::DISK)->assertExists((string) $draft->attachments->first()->storage_path);
});

it('reopens a draft with its saved attachments listed', function (): void {
    Storage::fake(EmailAttachment::DISK);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'Reopen me')
        ->set('attachments', [UploadedFile::fake()->create('brief.pdf', 20)])
        ->call('close');

    $draft = Email::query()->where('subject', 'Reopen me')->sole();

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: (string) $draft->getKey())
        ->assertSet('savedAttachments', [[
            'id' => (string) $draft->attachments->first()->getKey(),
            'filename' => 'brief.pdf',
            'size' => (int) $draft->attachments->first()->size,
        ]])
        ->assertSee('brief.pdf');
});

it('does not attach a saved draft file twice when the draft is saved again', function (): void {
    Storage::fake(EmailAttachment::DISK);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'Saved twice')
        ->set('attachments', [UploadedFile::fake()->create('once.pdf', 10)])
        ->call('minimize')
        ->call('minimize')
        ->call('close');

    $draft = Email::query()->where('subject', 'Saved twice')->sole();

    expect($draft->attachments)->toHaveCount(1);
});

it('removes a saved attachment from the draft and from disk', function (): void {
    Storage::fake(EmailAttachment::DISK);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'Drop the file')
        ->set('attachments', [UploadedFile::fake()->create('oops.pdf', 10)])
        ->call('close');

    $draft = Email::query()->where('subject', 'Drop the file')->sole();
    $attachment = $draft->attachments->first();

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: (string) $draft->getKey())
        ->call('removeSavedAttachment', (string) $attachment->getKey())
        ->assertSet('savedAttachments', []);

    Storage::disk(EmailAttachment::DISK)->assertMissing((string) $attachment->storage_path);
    expect($draft->refresh()->attachments)->toHaveCount(0)
        ->and($draft->has_attachments)->toBeFalse();
});

it('sends a reopened draft with its saved attachments and leaves no orphaned files', function (): void {
    Storage::fake(EmailAttachment::DISK);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'Send with attachment')
        ->set('attachments', [UploadedFile::fake()->create('contract.pdf', 30)])
        ->call('close');

    $draft = Email::query()->where('subject', 'Send with attachment')->sole();
    $draftPath = (string) $draft->attachments->first()->storage_path;

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: (string) $draft->getKey())
        ->set('to', ['lead@example.com'])
        ->set('bodyHtml', '<p>Signed copy attached</p>')
        ->call('send')
        ->assertHasNoErrors();

    $sent = Email::query()->where('subject', 'Send with attachment')->where('status', EmailStatus::QUEUED)->sole();
    $sentAttachment = $sent->attachments->sole();

    expect($sentAttachment->filename)->toBe('contract.pdf')
        // The queued email holds its own copy: deleting the draft (which send()
        // does straight after) must not strip the bytes out from under it.
        ->and($sentAttachment->storage_path)->not->toBe($draftPath);

    Storage::disk(EmailAttachment::DISK)->assertExists((string) $sentAttachment->storage_path);
    Storage::disk(EmailAttachment::DISK)->assertMissing($draftPath);
    expect(Email::query()->whereKey($draft->getKey())->exists())->toBeFalse();
});

it('deletes a draft\'s attachment files when the draft is deleted', function (): void {
    Storage::fake(EmailAttachment::DISK);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'Delete me')
        ->set('attachments', [UploadedFile::fake()->create('gone.pdf', 10)])
        ->call('close');

    $draft = Email::query()->where('subject', 'Delete me')->sole();
    $path = (string) $draft->attachments->first()->storage_path;

    resolve(DeleteEmailDraftAction::class)->execute($this->user, (string) $draft->getKey());

    Storage::disk(EmailAttachment::DISK)->assertMissing($path);
    expect(EmailAttachment::query()->where('email_id', $draft->getKey())->exists())->toBeFalse();
});

it('falls back to the default account and warns when a draft\'s connected account is no longer active', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['x@example.com'])
        ->set('subject', 'Stale account draft')
        ->set('bodyHtml', '<p>b</p>')
        ->call('minimize');

    $draft = Email::query()->where('status', EmailStatus::DRAFT)->sole();

    $fallbackAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->user->current_team_id,
        'status' => 'active',
    ]));

    $this->account->update(['status' => EmailAccountStatus::DISCONNECTED]);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open', draftId: $draft->id)
        ->assertSet('draftId', $draft->id)
        ->assertSet('subject', 'Stale account draft')
        ->assertSet('accountId', $fallbackAccount->id)
        ->assertNotified('Original account no longer connected');

    expect(Email::query()->where('status', EmailStatus::DRAFT)->whereKey($draft->id)->value('connected_account_id'))
        ->toBe($this->account->id);
});

it('fills subject and body from a template and keeps the signature below it', function (): void {
    $signature = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'content_html' => '<p>— Ada</p>',
    ]);

    $template = EmailTemplate::factory()->create([
        'team_id' => $this->user->current_team_id,
        'created_by' => $this->user->id,
        'is_shared' => true,
        'subject' => 'Renewal options',
        'body_html' => '<p>Here are your options.</p>',
    ]);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('signatureId', $signature->id)
        ->call('applyTemplate', $template->id)
        ->assertSet('subject', 'Renewal options')
        ->call('send')
        ->assertHasNoErrors();

    $email = Email::query()->where('subject', 'Renewal options')->sole();
    expect($email->body->body_html)->toContain('Here are your options.')
        ->and($email->body->body_html)->toContain('Ada');
});

it('saves the current message as a template from the composer', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'Renewal outreach')
        ->set('bodyHtml', '<p>Here are your options.</p>')
        ->callAction('createTemplate', [
            'name' => 'Renewal outreach',
            'subject' => 'Renewal outreach',
            'body_html' => '<p>Here are your options.</p>',
            'is_shared' => true,
        ])
        ->assertHasNoActionErrors();

    $template = EmailTemplate::query()->where('name', 'Renewal outreach')->sole();
    expect($template->team_id)->toBe($this->user->current_team_id)
        ->and($template->created_by)->toBe($this->user->id)
        ->and($template->is_shared)->toBeTrue()
        ->and($template->body_html)->toContain('Here are your options.');
});

it('creates a signature from the composer and applies it to the message', function (): void {
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Signature on the fly')
        ->set('bodyHtml', '<p>Hello there</p>')
        ->callAction('createSignature', [
            'name' => 'Work',
            'content_html' => '<p>— Ada, CEO</p>',
            'is_default' => true,
        ])
        ->assertHasNoActionErrors()
        ->call('send')
        ->assertHasNoErrors();

    $signature = EmailSignature::query()->where('name', 'Work')->sole();
    expect($signature->connected_account_id)->toBe($this->account->id)
        ->and($signature->is_default)->toBeTrue();

    $email = Email::query()->where('subject', 'Signature on the fly')->sole();
    expect($email->body->body_html)->toContain('Ada, CEO');
});

it('ignores a template belonging to another team', function (): void {
    $foreign = EmailTemplate::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'is_shared' => true,
        'subject' => 'Not yours',
    ]);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->call('applyTemplate', $foreign->id)
        ->assertSet('subject', null);
});

it('rejects attachments over the per-file and total size caps', function (): void {
    Storage::fake(EmailAttachment::DISK);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'Too heavy')
        ->set('bodyHtml', '<p>Body</p>')
        ->set('attachments', [
            UploadedFile::fake()->create('small.pdf', 100),
            // Over the 10 MB per-file cap.
            UploadedFile::fake()->create('huge.pdf', 11 * 1024),
            // Fits on its own, but pushes the message past the 15 MB total.
            UploadedFile::fake()->create('big-a.pdf', 9 * 1024),
            UploadedFile::fake()->create('big-b.pdf', 9 * 1024),
        ])
        ->assertSee('small.pdf')
        ->assertSee('big-a.pdf')
        ->assertDontSee('huge.pdf')
        ->assertDontSee('big-b.pdf')
        ->call('send')
        ->assertHasNoErrors();

    $email = Email::query()->where('subject', 'Too heavy')->sole();
    expect($email->attachments->pluck('filename')->all())->toEqualCanonicalizing(['small.pdf', 'big-a.pdf']);
});

it('drops a pending attachment when it is removed before sending', function (): void {
    Storage::fake(EmailAttachment::DISK);

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['lead@example.com'])
        ->set('subject', 'No attachment after all')
        ->set('bodyHtml', '<p>Body</p>')
        ->set('attachments', [
            UploadedFile::fake()->create('keep.pdf', 12),
            UploadedFile::fake()->create('drop.pdf', 12),
        ])
        // Each pending attachment is listed with its name and human-readable size.
        ->assertSee('keep.pdf')
        ->assertSee('drop.pdf')
        ->assertSee('12 KB')
        ->call('removeAttachment', 1)
        ->assertSee('keep.pdf')
        ->assertDontSee('drop.pdf')
        ->call('send');

    $email = Email::query()->where('subject', 'No attachment after all')->sole();
    expect($email->attachments()->pluck('filename')->all())->toBe(['keep.pdf']);
});
