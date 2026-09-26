<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\PeopleEmailsPage;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\BaseRecordEmailsPage;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;
use Relaticle\EmailIntegration\Services\PrivacyService;

mutates(
    PreferredEmailCopyService::class,
    PrivacyService::class,
    BaseRecordEmailsPage::class,
    BaseEmailsRelationManager::class,
    EmailVisibilityService::class,
);

/**
 * @return array{0: User, 1: ConnectedAccount}
 */
function createMailboxOwner(Workspace $team): array
{
    $user = User::factory()->create(['current_workspace_id' => $team->id]);
    $team->users()->attach($user, ['role' => 'member']);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $user->id,
    ]));

    return [$user, $account];
}

/**
 * @return array{0: Email, 1: Email}
 */
function createSyncedCopies(
    Workspace $team,
    User $first,
    ConnectedAccount $firstAccount,
    User $second,
    ConnectedAccount $secondAccount,
    string $messageId,
    string $subject,
): array {
    $firstCopy = Email::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $first->id,
        'connected_account_id' => $firstAccount->getKey(),
        'rfc_message_id' => $messageId,
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'subject' => $subject,
        'sent_at' => now(),
        'is_internal' => false,
    ]);

    $secondCopy = Email::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $second->id,
        'connected_account_id' => $secondAccount->getKey(),
        'rfc_message_id' => $messageId,
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'subject' => $subject,
        'sent_at' => now()->subMinute(),
        'is_internal' => false,
    ]);

    return [$firstCopy, $secondCopy];
}

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->ownerAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    [$this->workspacemate, $this->workspacemateAccount] = createMailboxOwner($this->workspace);

    $this->person = People::factory()->create([
        'workspace_id' => $this->workspace->id,
        'creator_id' => $this->owner->id,
    ]);

    [$this->ownerCopy, $this->workspacemateCopy] = createSyncedCopies(
        $this->workspace,
        $this->owner,
        $this->ownerAccount,
        $this->workspacemate,
        $this->workspacemateAccount,
        '<shared-thread@example.com>',
        'Acme renewal thread',
    );

    $this->person->emails()->attach([$this->ownerCopy->getKey(), $this->workspacemateCopy->getKey()]);
});

it('shows one record mailbox row when two teammates synced the same message', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    $page = livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()]);

    expect(substr_count($page->html(), 'Acme renewal thread'))->toBe(1);

    $page
        ->assertDontSee(__('filament/pages/email-inbox.list_row.request_access', ['name' => $this->owner->name]))
        ->assertDontSee(__('filament/pages/email-inbox.list_row.request_access', ['name' => $this->workspacemate->name]))
        ->call('selectEmail', $this->ownerCopy->getKey())
        ->assertSet('selectedEmailId', $this->ownerCopy->getKey());
});

it('does not ask a teammate to request access when the message is already in their mailbox', function (): void {
    $this->actingAs($this->workspacemate);
    Filament::setTenant($this->workspace);

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertDontSee(__('filament/pages/email-inbox.list_row.request_access', ['name' => $this->owner->name]))
        ->assertDontSee(__('filament/pages/email-inbox.list_row.request_access', ['name' => $this->workspacemate->name]))
        ->call('selectEmail', $this->workspacemateCopy->getKey())
        ->assertSet('selectedEmailId', $this->workspacemateCopy->getKey());
});

it('still asks a teammate without a mailbox copy to request access once', function (): void {
    $outsider = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($outsider, ['role' => 'member']);

    $this->actingAs($outsider);
    Filament::setTenant($this->workspace);

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee(__('filament/pages/email-inbox.list_row.request_access', ['name' => $this->owner->name]))
        ->assertDontSee(__('filament/pages/email-inbox.list_row.request_access', ['name' => $this->workspacemate->name]));
});

it('keeps distinct messages as separate record mailbox rows', function (): void {
    $other = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->ownerAccount->getKey(),
        'rfc_message_id' => '<other-thread@example.com>',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'subject' => 'Kickoff notes',
        'is_internal' => false,
    ]);

    $this->person->emails()->attach($other->getKey());

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->assertSee('Acme renewal thread')
        ->assertSee('Kickoff notes');
});

it('counts a duplicated synced message once on the emails tab badge', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    expect(EmailsRelationManager::getBadge($this->person, ViewPeople::class))->toBe('1');
});

it('hides the duplicate table row from the emails relation manager', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertCanSeeTableRecords([$this->ownerCopy])
        ->assertCanNotSeeTableRecords([$this->workspacemateCopy])
        ->assertTableActionHidden('requestAccess', $this->ownerCopy);
});

it('opens the sent email on the record mailbox after compose', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->dispatch('composer:sent', emailId: $this->ownerCopy->getKey())
        ->assertSet('selectedEmailId', $this->ownerCopy->getKey());
});
