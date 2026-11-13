<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Relaticle\ImportWizard\Enums\ImportOutcome;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Filament\Pages\ImportHistory;
use Relaticle\ImportWizard\Http\Controllers\DownloadFailedRowsController;
use Relaticle\ImportWizard\Models\FailedImportRow;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Notifications\ImportFinishedNotification;

mutates(DownloadFailedRowsController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspaceA = $this->user->currentWorkspace;
    $this->workspaceB = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $this->workspaceB->users()->attach($this->user, ['role' => 'member']);
    $this->user->switchWorkspace($this->workspaceA);

    $this->import = Import::factory()->create([
        'workspace_id' => $this->workspaceA->id,
        'user_id' => $this->user->id,
        'headers' => ['Name'],
        'failed_rows' => 1,
    ]);
    FailedImportRow::query()->create([
        'import_id' => $this->import->id,
        'workspace_id' => $this->workspaceA->id,
        'data' => ['Name' => ''],
        'validation_error' => 'Name is required',
    ]);
});

it('downloads the failed rows from the link in the bell after the member opened another workspace', function (): void {
    $this->user->notify(new ImportFinishedNotification($this->import, ImportOutcome::CompletedWithFailures));
    $url = ImportFinishedNotification::present($this->user->notifications()->sole())->getActions()[1]->getUrl();

    $this->user->switchWorkspace($this->workspaceB);

    $response = $this->actingAs($this->user)->get($url);

    $response->assertOk();
    expect($response->streamedContent())->toContain('Name is required');
});

it('refuses the download to a user outside the import workspace', function (): void {
    $outsider = User::factory()->withPersonalWorkspace()->create();
    $url = URL::temporarySignedRoute('import-history.failed-rows.download', now()->addHour(), ['import' => $this->import]);

    $this->actingAs($outsider)->get($url)->assertForbidden();
});

function viewerOf(object $context): User
{
    $viewer = User::factory()->withPersonalWorkspace()->create();
    $context->workspaceA->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($context->workspaceA);

    return $viewer;
}

function stalledImportIn(object $context): Import
{
    $context->travelTo(now()->subMinutes(31));
    $stalled = Import::factory()->create([
        'workspace_id' => $context->workspaceA->id,
        'user_id' => $context->user->id,
        'status' => ImportStatus::Importing,
    ]);
    $context->travelBack();

    return $stalled;
}

it('opens the import history page to a member who can import', function (): void {
    $this->actingAs($this->user)
        ->get(ImportHistory::getUrl(tenant: $this->workspaceA))
        ->assertOk();
});

it('refuses the import history page to a viewer', function (): void {
    $this->actingAs(viewerOf($this))
        ->get(ImportHistory::getUrl(tenant: $this->workspaceA))
        ->assertForbidden();
});

it('downloads the failed rows for a member who can import', function (): void {
    $url = URL::temporarySignedRoute('import-history.failed-rows.download', now()->addHour(), ['import' => $this->import]);

    $response = $this->actingAs($this->user)->get($url);

    $response->assertOk();
    expect($response->streamedContent())->toContain('Name is required');
});

it('refuses the failed rows download to a viewer', function (): void {
    $url = URL::temporarySignedRoute('import-history.failed-rows.download', now()->addHour(), ['import' => $this->import]);

    $this->actingAs(viewerOf($this))->get($url)->assertForbidden();
});

it('refuses marking an import failed once the member is demoted to viewer', function (): void {
    $stalled = stalledImportIn($this);

    $member = User::factory()->withPersonalWorkspace()->create();
    $this->workspaceA->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($this->workspaceA);

    $this->actingAs($member);
    Filament::setTenant($this->workspaceA);
    $page = Livewire::test(ImportHistory::class);

    $this->workspaceA->users()->updateExistingPivot($member->id, ['role' => WorkspaceRole::Viewer->value]);
    $member->unsetRelation('workspaces');

    $page->call('mountAction', [['name' => 'markFailed', 'context' => ['table' => true, 'recordKey' => (string) $stalled->getKey()]]])
        ->assertForbidden();

    expect($stalled->fresh()->status)->toBe(ImportStatus::Importing);
});

it('marks a stalled import failed for a member who can import', function (): void {
    $stalled = stalledImportIn($this);

    $this->actingAs($this->user);
    Filament::setTenant($this->workspaceA);

    Livewire::test(ImportHistory::class)->callTableAction('markFailed', $stalled);

    expect($stalled->fresh()->status)->toBe(ImportStatus::Failed);
});
