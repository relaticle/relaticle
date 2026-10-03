<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource\Pages\ListConnectedAccounts;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource\Pages\ViewConnectedAccount;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ViewWorkspace;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\RelationManagers\MailboxesRelationManager;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(ConnectedAccountResource::class, MailboxesRelationManager::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

function syncedMailbox(array $attributes = []): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'sync_cursor' => 'cursor',
        'last_synced_at' => now()->subMinutes(5),
        ...$attributes,
    ]);
}

it('lists mailboxes across all workspaces', function (): void {
    $first = syncedMailbox(['workspace_id' => Workspace::factory()->create(['name' => 'Acme'])]);
    $second = syncedMailbox(['workspace_id' => Workspace::factory()->create(['name' => 'Globex'])]);

    livewire(ListConnectedAccounts::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanRenderTableColumn('email_address')
        ->assertCanRenderTableColumn('status')
        ->assertCanRenderTableColumn('workspace.name')
        ->assertCanRenderTableColumn('last_synced_at')
        ->assertSee('Acme')
        ->assertSee('Globex');
});

it('filters to mailboxes that failed, need sign-in or stopped syncing', function (): void {
    $healthy = syncedMailbox();
    $importing = ConnectedAccount::factory()->create();
    $disconnected = syncedMailbox(['status' => EmailAccountStatus::DISCONNECTED, 'last_synced_at' => now()->subDays(3)]);
    $failed = syncedMailbox(['status' => EmailAccountStatus::ERROR, 'last_error' => 'Token refresh failed']);
    $needsSignIn = syncedMailbox(['status' => EmailAccountStatus::REAUTH_REQUIRED]);
    $late = syncedMailbox(['last_synced_at' => now()->subMinutes(61)]);
    $neverSynced = syncedMailbox(['last_synced_at' => null]);

    livewire(ListConnectedAccounts::class)
        ->filterTable('needs_attention')
        ->assertCanSeeTableRecords([$failed, $needsSignIn, $late, $neverSynced])
        ->assertCanNotSeeTableRecords([$healthy, $importing, $disconnected]);
});

it('keeps a mailbox that synced within the hour out of the attention list', function (): void {
    $onTime = syncedMailbox(['last_synced_at' => now()->subMinutes(59)]);

    livewire(ListConnectedAccounts::class)
        ->filterTable('needs_attention')
        ->assertCanNotSeeTableRecords([$onTime]);
});

it('filters mailboxes by status', function (): void {
    $active = syncedMailbox();
    $failed = syncedMailbox(['status' => EmailAccountStatus::ERROR]);

    livewire(ListConnectedAccounts::class)
        ->filterTable('status', [EmailAccountStatus::ERROR->value])
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$active]);
});

it('shows the sync error on the mailbox page and never the provider tokens', function (): void {
    $mailbox = syncedMailbox([
        'status' => EmailAccountStatus::ERROR,
        'last_error' => 'Token refresh failed',
        'access_token' => 'secret-access-token',
        'refresh_token' => 'secret-refresh-token',
    ]);

    livewire(ViewConnectedAccount::class, ['record' => $mailbox->getKey()])
        ->assertSuccessful()
        ->assertSee($mailbox->email_address)
        ->assertSee('Token refresh failed')
        ->assertDontSee('secret-access-token')
        ->assertDontSee('secret-refresh-token');
});

it('offers no way to create, edit or delete a mailbox', function (): void {
    $mailbox = syncedMailbox();

    expect(ConnectedAccountResource::canCreate())->toBeFalse()
        ->and(ConnectedAccountResource::canEdit($mailbox))->toBeFalse()
        ->and(ConnectedAccountResource::canDelete($mailbox))->toBeFalse();
});

it('shows only the viewed workspace mailboxes on its page', function (): void {
    $workspace = Workspace::factory()->create();
    $own = syncedMailbox(['workspace_id' => $workspace]);
    $other = syncedMailbox();

    livewire(MailboxesRelationManager::class, ['ownerRecord' => $workspace, 'pageClass' => ViewWorkspace::class])
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$other]);

    expect(MailboxesRelationManager::getBadge($workspace, ViewWorkspace::class))->toBe('1');
});

it('hides mailboxes while the email integration is off', function (): void {
    $workspace = Workspace::factory()->create();
    config()->set('relaticle.features.email_integration', false);
    Feature::flushCache();
    Feature::for(null)->deactivate(EmailIntegration::class);

    expect(ConnectedAccountResource::canAccess())->toBeFalse()
        ->and(MailboxesRelationManager::canViewForRecord($workspace, ViewWorkspace::class))->toBeFalse();

    livewire(ListConnectedAccounts::class)->assertForbidden();
});
