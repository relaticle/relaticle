<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Components\Forms\MergeRecordPicker;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Models\Note;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(MergeRecordPicker::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function mergeDialogHtml(Testable $livewire): string
{
    return implode('', $livewire->effects['partials'] ?? []);
}

it('says the picked record is unavailable when it is deleted while the dialog is open', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline']);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Logistics']);
    $message = __('filament/record-page.merge.unavailable');

    $dialog = livewire(ViewCompany::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $duplicate->getKey(), 'keep_picked' => false]);

    expect(mergeDialogHtml($dialog))->toContain('Harborline Logistics')
        ->not->toContain($message);

    $duplicate->delete();

    expect(mergeDialogHtml($dialog->call('$refresh')))->toContain($message);
});

it('says nothing is unavailable before a record is picked', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline']);

    $dialog = livewire(ViewCompany::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction());

    expect(mergeDialogHtml($dialog))->toContain('data-merge-dialog')
        ->not->toContain(__('filament/record-page.merge.unavailable'));
});

it('renders the dialog heading as markup, not as escaped text', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Swaniawski Inc']);

    $dialog = mergeDialogHtml(livewire(ViewCompany::class, ['record' => $kept->getKey()])->mountAction(mergeRecordAction()));

    expect($dialog)->toContain('Swaniawski Inc')
        ->not->toContain('&lt;span');
});



it('refuses the record search once the member loses merge rights', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->switchWorkspace($this->workspace);
    $this->actingAs($admin);

    $livewire = livewire(ViewCompany::class, ['record' => $kept->getKey()])->mountAction(mergeRecordAction());
    $picker = mergeDialogPicker($livewire);

    $this->workspace->users()->updateExistingPivot($admin->getKey(), ['role' => WorkspaceRole::Viewer->value]);
    $this->actingAs($admin->fresh());

    $livewire->call('callSchemaComponentMethod', $picker->getKey(), 'searchRecords', ['search' => ''])
        ->assertForbidden();
});

function mergeDialogPicker(Testable $livewire): MergeRecordPicker
{
    $instance = $livewire->instance();

    $picker = $instance->getSchema((string) $instance->getMountedActionSchemaName())
        ?->getComponent(fn (Component $component): bool => $component instanceof MergeRecordPicker);

    assert($picker instanceof MergeRecordPicker);

    return $picker;
}
