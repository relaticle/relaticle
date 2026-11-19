<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Relaticle\ImportWizard\Actions\MarkImportFailed;
use Relaticle\ImportWizard\Enums\ImportOutcome;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Filament\Pages\ImportHistory;
use Relaticle\ImportWizard\Http\Controllers\DownloadFailedRowsController;
use Relaticle\ImportWizard\Models\FailedImportRow;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Notifications\ImportFinishedNotification;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(DownloadFailedRowsController::class, ImportHistory::class, MarkImportFailed::class);

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

function importWorkspaceMemberWithRole(object $context, WorkspaceRole $role): User
{
    $member = User::factory()->withPersonalWorkspace()->create();
    $context->workspaceA->users()->attach($member, ['role' => $role->value]);
    $member->switchWorkspace($context->workspaceA);

    return $member;
}

function viewerOf(object $context): User
{
    return importWorkspaceMemberWithRole($context, WorkspaceRole::Viewer);
}

function importerOf(object $context, string $who): User
{
    return $who === 'owner' ? $context->user : importWorkspaceMemberWithRole($context, WorkspaceRole::Member);
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

it('opens the import history page to a user who can import', function (string $who): void {
    $this->actingAs(importerOf($this, $who))
        ->get(ImportHistory::getUrl(tenant: $this->workspaceA))
        ->assertOk();
})->with(['owner', 'member']);

it('refuses the import history page to a viewer', function (): void {
    $this->actingAs(viewerOf($this))
        ->get(ImportHistory::getUrl(tenant: $this->workspaceA))
        ->assertForbidden();
});

it('downloads the failed rows for a user who can import', function (string $who): void {
    $url = URL::temporarySignedRoute('import-history.failed-rows.download', now()->addHour(), ['import' => $this->import]);

    $response = $this->actingAs(importerOf($this, $who))->get($url);

    $response->assertOk();
    expect($response->streamedContent())->toContain('Name is required')->toContain('Import Error')->not->toContain('import-wizard-new::');
})->with(['owner', 'member']);

it('refuses the failed rows download to a viewer', function (): void {
    $url = URL::temporarySignedRoute('import-history.failed-rows.download', now()->addHour(), ['import' => $this->import]);

    $this->actingAs(viewerOf($this))->get($url)->assertForbidden();
});

it('refuses the mark failed action on the page once the member is demoted to viewer', function (): void {
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

it('marks a stalled import failed for a user who can import', function (string $who): void {
    $stalled = stalledImportIn($this);

    $this->actingAs(importerOf($this, $who));
    Filament::setTenant($this->workspaceA);

    Livewire::test(ImportHistory::class)->callTableAction('markFailed', $stalled);

    expect($stalled->fresh()->status)->toBe(ImportStatus::Failed);
})->with(['owner', 'member']);

it('refuses the mark failed action to a viewer', function (): void {
    $stalled = stalledImportIn($this);

    expect(fn () => resolve(MarkImportFailed::class)->execute(viewerOf($this), $stalled))->toThrow(HttpException::class);
    expect($stalled->fresh()->status)->toBe(ImportStatus::Importing);
});

it('refuses marking an import failed unless it is importing and stalled', function (ImportStatus $status, bool $stalled): void {
    $import = Import::factory()->create([
        'workspace_id' => $this->workspaceA->id,
        'user_id' => $this->user->id,
        'status' => $status,
        'updated_at' => $stalled ? now()->subMinutes(31) : now(),
    ]);

    expect(fn () => resolve(MarkImportFailed::class)->execute($this->user, $import))->toThrow(HttpException::class);
    expect($import->fresh()->status)->toBe($status);
})->with([
    'completed' => [ImportStatus::Completed, true],
    'importing but recent' => [ImportStatus::Importing, false],
]);
