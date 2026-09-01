<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Livewire\AccessRequestsTable;
use Relaticle\EmailIntegration\Livewire\DraftsTable;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Livewire\OutboxTable;
use Relaticle\EmailIntegration\Livewire\TemplatesTable;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailTemplate;

use function Pest\Laravel\actingAs;

mutates(EmailInboxPage::class, AccessRequestsTable::class, DraftsTable::class, TemplatesTable::class, EmailPageTab::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;
    actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]));
});

function makeDraft(User $user, ConnectedAccount $account, array $overrides = []): Email
{
    return Email::query()->create(array_merge([
        'team_id' => $user->currentTeam->id,
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'subject' => 'Half-written pitch',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::DRAFT,
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'creation_source' => EmailCreationSource::COMPOSE,
    ], $overrides));
}

it('uses an outlined icon for the failed tab', function (): void {
    expect(EmailPageTab::FAILED->getIcon())->toBe(Heroicon::OutlinedExclamationCircle);
});

it('defaults to the first tab and switches between tabs', function (): void {
    Livewire::test(EmailInboxPage::class)
        ->assertSet('tab', EmailPageTab::DRAFTS)
        ->call('setTab', 'outbox')
        ->assertSet('tab', EmailPageTab::OUTBOX)
        ->call('setTab', 'failed')
        ->assertSet('tab', EmailPageTab::FAILED)
        ->call('setTab', 'templates')
        ->assertSet('tab', EmailPageTab::TEMPLATES)
        ->call('setTab', 'requests')
        ->assertSet('tab', EmailPageTab::REQUESTS)
        ->assertSee('email-integration.access-requests-table');
});

it('loads the failed tab from the URL', function (): void {
    Livewire::withQueryParams(['tab' => 'failed'])
        ->test(EmailInboxPage::class)
        ->assertSet('tab', EmailPageTab::FAILED);
});

it('counts drafts, pending outbox mail and available templates for the tab badges', function (): void {
    makeDraft($this->user, $this->account);
    makeDraft($this->user, $this->account, ['subject' => 'Second draft']);

    Email::query()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Waiting to go out',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    Email::query()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Could not be delivered',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::FAILED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    EmailTemplate::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'is_shared' => false,
    ]);

    $counts = Livewire::test(EmailInboxPage::class)->instance()->tabCounts();

    expect($counts)->toBe([
        'drafts' => 2,
        'outbox' => 1,
        'failed' => 1,
        'templates' => 1,
        'requests' => 0,
    ]);
});

it('shows received access requests in the emails tab bar', function (): void {
    $requester = User::factory()->create(['current_team_id' => $this->team->id]);

    EmailAccessRequest::factory()->pending()->create([
        'owner_id' => $this->user->id,
        'requester_id' => $requester->id,
        'email_id' => Email::factory()->private()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'connected_account_id' => $this->account->id,
        ])->id,
    ]);

    $page = Livewire::test(EmailInboxPage::class)
        ->assertSee('Requests');

    expect($page->instance()->tabCounts())
        ->toMatchArray(['requests' => 1]);
});

it('refreshes the tab badges when the composer saves a draft', function (): void {
    $page = Livewire::test(EmailInboxPage::class);

    expect($page->instance()->tabCounts()['drafts'])->toBe(0);

    // What closing the floating composer on a half-written message does.
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'Saved on close')
        ->call('close')
        ->assertDispatched('drafts:changed');

    $page->dispatch('drafts:changed');

    expect($page->instance()->tabCounts()['drafts'])->toBe(1);
});

it('refreshes the outbox and failed badges when a failed email is retried', function (): void {
    $failed = Email::query()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Retry me',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::FAILED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    $page = Livewire::test(EmailInboxPage::class);

    expect($page->instance()->tabCounts())
        ->toMatchArray(['outbox' => 0, 'failed' => 1]);

    Livewire::test(OutboxTable::class, ['lockedStatus' => EmailStatus::FAILED])
        ->callAction(TestAction::make('retry')->table($failed))
        ->assertDispatched('outbox:changed');

    $page->dispatch('outbox:changed');

    expect($page->instance()->tabCounts())
        ->toMatchArray(['outbox' => 1, 'failed' => 0]);
});

it('does not mark an email as read when the page is loaded on another tab', function (): void {
    $email = Email::factory()->inbound()->full()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'sent_at' => now(),
    ]);

    Livewire::withQueryParams(['tab' => 'drafts'])
        ->test(EmailInboxPage::class)
        ->assertSet('tab', EmailPageTab::DRAFTS)
        ->assertSet('selectedEmailId', null);

    expect($email->reads()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('saves an email privacy tier from the sharing cards', function (): void {
    $email = Email::factory()->inbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);

    Livewire::test(EmailInboxPage::class)
        ->callAction('manageSharing', data: [
            'privacy_tier' => EmailPrivacyTier::FULL->value,
            'shares' => [],
        ], arguments: ['emailId' => $email->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Sharing settings saved.');

    expect($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('lists only the signed-in user\'s own drafts', function (): void {
    $mine = makeDraft($this->user, $this->account);

    $teammate = User::factory()->create(['current_team_id' => $this->team->id]);
    $theirAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
        'status' => 'active',
    ]));
    $theirs = makeDraft($teammate, $theirAccount, ['subject' => 'Not yours']);

    Livewire::test(DraftsTable::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('opens a draft in the composer', function (): void {
    $draft = makeDraft($this->user, $this->account);

    Livewire::test(DraftsTable::class)
        ->callAction(TestAction::make('openDraft')->table($draft))
        ->assertDispatched('composer:open', draftId: (string) $draft->getKey());
});

it('deletes a draft from the drafts table, attachment rows and files included', function (): void {
    Storage::fake(EmailAttachment::DISK);

    // Save through the composer so the draft carries a real stored attachment.
    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->set('subject', 'Delete from the list')
        ->set('attachments', [UploadedFile::fake()->create('attached.pdf', 15)])
        ->call('close');

    $draft = Email::query()->where('subject', 'Delete from the list')->sole();
    $path = (string) $draft->attachments->first()->storage_path;

    Livewire::test(DraftsTable::class)
        ->callAction(TestAction::make('deleteDraft')->table($draft))
        ->assertNotified();

    expect(Email::withTrashed()->whereKey($draft->getKey())->exists())->toBeFalse()
        ->and(EmailAttachment::query()->where('email_id', $draft->getKey())->exists())->toBeFalse();

    Storage::disk(EmailAttachment::DISK)->assertMissing($path);
});

it('lists shared and own templates in the templates tab', function (): void {
    $mine = EmailTemplate::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
        'is_shared' => false,
    ]);

    $foreign = EmailTemplate::factory()->create([
        'team_id' => User::factory()->withTeam()->create()->current_team_id,
        'is_shared' => true,
    ]);

    Livewire::test(TemplatesTable::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});
