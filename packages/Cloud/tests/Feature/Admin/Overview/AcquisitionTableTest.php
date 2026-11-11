<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
use App\Features\EmailIntegration;
use App\Models\ActivityLog\Activity;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\AcquisitionTable;
use Relaticle\Cloud\Admin\Metrics\AcquisitionFunnel;
use Relaticle\Cloud\Analytics\Models\Acquisition;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(AcquisitionTable::class, AcquisitionFunnel::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    $this->travelTo(now()->startOfDay());
});

function captureOverviewAcquisition(User $user, string $source = 'github'): Acquisition
{
    return Acquisition::query()->create([
        'user_id' => $user->getKey(), 'workspace_id' => OverviewData::workspaceOf($user)->getKey(),
        'source' => $source, 'landing' => 'pricing', 'captured_at' => $user->created_at,
        'workspace_linked_at' => $user->created_at,
    ]);
}

it('shows the prospective empty state without backfilling historical owners', function (): void {
    OverviewData::owner();

    livewire(AcquisitionTable::class)->assertOk()
        ->assertSee('Attribution starts with this release')
        ->assertViewHas('rows', []);
});

it('keeps genuine signup exclusions and waits for seven complete follow-up days', function (): void {
    $fast = OverviewData::owner(now()->subDays(8));
    OverviewData::ownRecord(OverviewData::workspaceOf($fast), $fast, now()->subDay()->endOfDay());
    $slow = OverviewData::owner(now()->subDays(9));
    OverviewData::ownRecord(OverviewData::workspaceOf($slow), $slow, now());
    $notMature = OverviewData::owner(now()->subDays(7));
    OverviewData::ownRecord(OverviewData::workspaceOf($notMature), $notMature, now()->subDays(7));
    $recent = OverviewData::owner(now()->subDay());
    $unverified = OverviewData::owner(attributes: ['email_verified_at' => null]);
    $internal = OverviewData::internalOwner();
    $invitee = OverviewData::owner();
    OverviewData::workspaceOf($fast)->users()->attach($invitee, ['role' => WorkspaceRole::Member->value]);

    foreach ([$fast, $slow, $notMature, $recent, $unverified, $internal, $invitee] as $user) {
        captureOverviewAcquisition($user);
    }

    livewire(AcquisitionTable::class)->assertOk()
        ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 1
            && $rows[0]['signups'] === 4 && $rows[0]['workspaces'] === 4
            && $rows[0]['eligible'] === 2 && $rows[0]['first_value'] === 1)
        ->assertSee('1 / 2');
});

it('separates stored conversion outcomes from retained product adoption', function (): void {
    Feature::define(EmailIntegration::class, true);
    $active = OverviewData::owner();
    $error = OverviewData::owner();
    $disconnected = OverviewData::owner();

    foreach ([$active, $error, $disconnected] as $user) {
        captureOverviewAcquisition($user);
    }

    $workspace = OverviewData::workspaceOf($active);
    Acquisition::query()->whereKey($active->getKey())->update([
        'setup_completed_at' => now(), 'first_checkout_at' => now(),
        'first_paid_at' => now(), 'first_renewal_failed_at' => now(),
    ]);
    OverviewData::trial($workspace);
    OverviewData::ownRecord($workspace, $active, now());
    OverviewData::typedMessage($workspace, $active, now());
    ConnectedAccount::factory()->create(['workspace_id' => $workspace, 'user_id' => $active, 'last_synced_at' => now(), 'sync_cursor' => 'saved-cursor']);
    ConnectedAccount::factory()->error()->create(['workspace_id' => OverviewData::workspaceOf($error), 'user_id' => $error, 'last_synced_at' => now(), 'sync_cursor' => 'stale-cursor']);
    ConnectedAccount::factory()->disconnected()->create(['workspace_id' => OverviewData::workspaceOf($disconnected), 'user_id' => $disconnected]);
    Import::factory()->create(['workspace_id' => $workspace, 'user_id' => $active, 'status' => ImportStatus::Completed, 'updated_rows' => 3, 'failed_rows' => 1, 'completed_at' => now()]);
    Import::factory()->create(['workspace_id' => OverviewData::workspaceOf($error), 'user_id' => $error, 'status' => ImportStatus::Completed, 'created_rows' => 0, 'failed_rows' => 2, 'completed_at' => now()]);
    Activity::query()->create(['workspace_id' => $workspace->getKey(), 'description' => 'Company updated', 'event' => 'updated', 'properties' => ['source' => CreationSource::MCP->value]]);

    livewire(AcquisitionTable::class)->assertOk()->assertViewHas('rows', fn (array $rows): bool => count($rows) === 1 && $rows[0] === [
        'source' => 'github', 'campaign' => null, 'landing' => 'pricing', 'signups' => 3,
        'eligible' => 0, 'first_value' => 0, 'workspaces' => 3, 'setup' => 1,
        'trial' => 1, 'checkout' => 1, 'paid' => 1, 'renewal_failed' => 1,
        'chat' => 1, 'mcp' => 1, 'import' => 1, 'partial_import' => 1, 'mailbox' => 2, 'healthy_sync' => 1,
        'mcp_clients' => [],
    ])->assertSee('Not mature');
});

it('keeps unknown sources and distinct campaign landing groups visible', function (): void {
    captureOverviewAcquisition(OverviewData::owner(), 'unknown');
    captureOverviewAcquisition(OverviewData::owner())->update(['campaign' => 'autumn-launch', 'landing' => 'home']);
    captureOverviewAcquisition(OverviewData::owner())->update(['campaign' => 'autumn-launch']);

    livewire(AcquisitionTable::class)->assertOk()
        ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 3
            && array_column($rows, 'signups') === [1, 1, 1]
            && array_column($rows, 'landing') === ['home', 'pricing', 'pricing'])
        ->assertSee('Unknown')->assertSee('autumn-launch');
});

it('counts workspaces per connected MCP client, readers included', function (): void {
    $both = OverviewData::owner();
    $claude = OverviewData::owner();
    $unconnected = OverviewData::owner();
    $outsider = OverviewData::owner();

    foreach ([$both, $claude, $unconnected] as $user) {
        captureOverviewAcquisition($user);
    }

    OverviewData::mcpConnector(OverviewData::workspaceOf($both), $both, 'Claude');
    OverviewData::mcpConnector(OverviewData::workspaceOf($both), $both, 'Claude');
    OverviewData::mcpConnector(OverviewData::workspaceOf($both), $both, 'ChatGPT');
    OverviewData::mcpConnector(OverviewData::workspaceOf($claude), $claude, 'Claude Code (acme-crm)');
    OverviewData::mcpConnector(OverviewData::workspaceOf($claude), $claude, 'Claude');
    OverviewData::mcpConnector(OverviewData::workspaceOf($outsider), $outsider, 'Codex');

    livewire(AcquisitionTable::class)->assertOk()
        ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 1
            && $rows[0]['mcp'] === 0
            && $rows[0]['mcp_clients'] === ['Claude' => 2, 'ChatGPT' => 1, 'Claude Code' => 1])
        ->assertSee('Claude 2, ChatGPT 1, Claude Code 1');
});
