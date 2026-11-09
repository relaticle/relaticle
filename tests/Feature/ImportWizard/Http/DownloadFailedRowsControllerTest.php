<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Relaticle\ImportWizard\Enums\ImportOutcome;
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
