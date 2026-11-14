<?php

declare(strict_types=1);

use App\Filament\Components\Forms\MergeRecordPicker;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Models\User;
use Filament\Facades\Filament;
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
        ->setActionData(['duplicate_id' => $duplicate->getKey(), 'keep' => 'current']);

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
