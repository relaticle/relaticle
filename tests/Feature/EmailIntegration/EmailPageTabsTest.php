<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
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
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailTemplate;

use function Pest\Laravel\actingAs;

mutates(EmailInboxPage::class, AccessRequestsTable::class, DraftsTable::class, TemplatesTable::class, EmailPageTab::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]));
});

function makeDraft(User $user, ConnectedAccount $account, array $overrides = []): Email
{
    return Email::query()->create(array_merge([
        'workspace_id' => $user->currentWorkspace->id,
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

it('uses an outlined key for the requests empty state', function (): void {
    expect(Livewire::test(AccessRequestsTable::class)->instance()->getTable()->getEmptyStateIcon())
        ->toBe(Heroicon::OutlinedKey);
});

it('shows the emails icon beside the page title', function (): void {
    Livewire::test(EmailInboxPage::class)
        ->assertSeeHtml('fi-topbar-page-icon');
});

it('defaults to the first tab and switches between tabs', function (): void {
    Livewire::test(EmailInboxPage::class)
        ->assertSet('tab', EmailPageTab::DRAFTS)
        ->call('setTab', 'outbox')
        ->assertSet('tab', EmailPageTab::OUTBOX)
        ->call('setTab', 'failed')
        ->assertSet('tab', EmailPageTab::FAILED)
        ->call('setTab', 'templates')
        ->assertSet('tab', EmailPageTab::TEMPLATES);
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
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Waiting to go out',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    Email::query()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Could not be delivered',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::FAILED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    EmailTemplate::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'is_shared' => false,
    ]);

    $counts = Livewire::test(EmailInboxPage::class)->instance()->tabCounts();

    expect($counts)->toBe([
        'drafts' => 2,
        'outbox' => 1,
        'failed' => 1,
        'templates' => 1,
    ]);
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
        'workspace_id' => $this->workspace->id,
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

it('offers a direct gmail connect on drafts when no mailbox is connected', function (): void {
    config()->set('services.gmail.client_id', 'gmail-client');

    $this->account->forceDelete();

    Livewire::test(DraftsTable::class)
        ->assertSee(__('filament/pages/email-accounts.not_connected.inbox.heading'))
        ->assertSee(__('filament/pages/email-accounts.actions.connect_gmail'))
        ->tap(fn ($component) => assertActionHasMailboxOAuthUrl(
            $component,
            TestAction::make('connectMailbox')->table(),
            'gmail',
            $this->account->workspace,
        ))
        ->assertDontSee(__('filament/pages/email-inbox.drafts.empty.heading'))
        ->assertDontSee(__('filament/emails/composer.grant_send.description'))
        ->assertDontSee(__('filament/concerns/email-compose.actions.compose.label'))
        ->assertTableEmptyStateActionsExistInOrder(['composeEmail', 'connectMailbox', 'connectAzure']);
});

it('opens the composer from the drafts empty state when a mailbox is connected', function (): void {
    Livewire::test(DraftsTable::class)
        ->assertSee(__('filament/pages/email-inbox.drafts.empty.heading'))
        ->assertSee(__('filament/concerns/email-compose.actions.compose.label'))
        ->assertTableHeaderActionsExistInOrder(['composeEmail'])
        ->assertTableEmptyStateActionsExistInOrder(['composeEmail', 'connectMailbox', 'connectAzure'])
        ->assertTableActionHidden('connectMailbox')
        ->assertTableActionHidden('connectAzure');
});

it('offers a microsoft mailbox on drafts once its client is configured', function (): void {
    config()->set('services.azure.client_id', 'azure-client');
    $this->account->forceDelete();

    Livewire::test(DraftsTable::class)
        ->assertSee(__('filament/pages/email-accounts.actions.connect_azure'))
        ->tap(fn ($component) => assertActionHasMailboxOAuthUrl(
            $component,
            TestAction::make('connectAzure')->table(),
            'azure',
            $this->account->workspace,
        ));
});

it('hides the microsoft mailbox on drafts without a microsoft client', function (): void {
    config()->set('services.azure.client_id');
    $this->account->forceDelete();

    Livewire::test(DraftsTable::class)
        ->assertDontSee(__('filament/pages/email-accounts.actions.connect_azure'))
        ->assertTableActionHidden('connectAzure');
});

it('keeps the drafts empty copy when the mailbox cannot send', function (): void {
    $this->account->update([
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    Livewire::test(DraftsTable::class)
        ->assertSee(__('filament/pages/email-inbox.drafts.empty.heading'))
        ->assertDontSee(__('filament/pages/email-accounts.not_connected.inbox.heading'))
        ->assertDontSee(__('filament/emails/composer.grant_send.description'));
});

it('keeps the drafts empty copy when the mailbox has a sync error', function (): void {
    $this->account->update([
        'status' => EmailAccountStatus::ERROR,
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    Livewire::test(DraftsTable::class)
        ->assertSee(__('filament/pages/email-inbox.drafts.empty.heading'))
        ->assertDontSee(__('filament/pages/email-accounts.not_connected.inbox.heading'));
});

it('keeps the drafts empty copy when the mailbox can send', function (): void {
    Livewire::test(DraftsTable::class)
        ->assertSee(__('filament/pages/email-inbox.drafts.empty.heading'))
        ->assertDontSee(__('filament/emails/composer.grant_send.description'));
});

it('lists drafts even when the mailbox cannot send', function (): void {
    $this->account->update([
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    $draft = makeDraft($this->user, $this->account);

    Livewire::test(DraftsTable::class)
        ->assertCanSeeTableRecords([$draft])
        ->assertDontSee(__('filament/emails/composer.grant_send.description'));
});

it('lists only the signed-in user\'s own drafts', function (): void {
    $mine = makeDraft($this->user, $this->account);

    $teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $theirAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $teammate->id,
        'status' => 'active',
    ]));
    $theirs = makeDraft($teammate, $theirAccount, ['subject' => 'Not yours']);

    Livewire::test(DraftsTable::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('previews the draft body on one line under its subject', function (): void {
    $draft = makeDraft($this->user, $this->account, ['snippet' => "Hi Dana,\n\n  thanks &amp; talk soon"]);

    Livewire::test(DraftsTable::class)
        ->assertTableColumnHasDescription('subject', 'Hi Dana, thanks & talk soon', $draft)
        ->assertTableColumnDoesNotExist('participants_to');
});

it('opens a draft in the composer', function (): void {
    $draft = makeDraft($this->user, $this->account);

    $openDraft = Livewire::test(DraftsTable::class)->instance()->getTable()->getAction('openDraft');

    expect($openDraft->record($draft)->getLivewireClickHandler())
        ->toStartWith("\$dispatch('composer:open'")
        ->toContain((string) $draft->getKey());
});

it('opens a draft in the composer from a click on its row', function (): void {
    $draft = makeDraft($this->user, $this->account);

    Livewire::test(DraftsTable::class)
        ->call('mountTableAction', 'openDraft', (string) $draft->getKey())
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

it('shows create on the templates empty state', function (): void {
    Livewire::test(TemplatesTable::class)
        ->assertSee(__('filament/resources/email-template.empty.heading'))
        ->assertSee(__('filament/resources/email-template.empty.description'))
        ->assertSee(__('filament/resources/email-template.actions.create.label'))
        ->assertTableEmptyStateActionsExistInOrder(['create']);
});

it('lists shared and own templates in the templates tab', function (): void {
    $mine = EmailTemplate::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'is_shared' => false,
    ]);

    $foreign = EmailTemplate::factory()->create([
        'workspace_id' => User::factory()->withWorkspace()->create()->current_workspace_id,
        'is_shared' => true,
    ]);

    Livewire::test(TemplatesTable::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});
