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
use Relaticle\EmailIntegration\Models\EmailSignature;

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

it('warns instead of silently discarding pending attachments when the composer is closed', function (): void {
    Storage::fake('local');

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('to', ['x@example.com'])
        ->set('subject', 'Has an attachment')
        ->set('bodyHtml', '<p>b</p>')
        ->set('attachments', [UploadedFile::fake()->create('quote.pdf', 12)])
        ->call('close')
        ->assertNotified('Attachments won\'t be saved');

    $draft = Email::query()->where('status', EmailStatus::DRAFT)->where('subject', 'Has an attachment')->sole();
    expect($draft->has_attachments)->toBeFalse();
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
