<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\User;
use App\Queries\Crm\DuplicateRecordQuery;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(DuplicateRecordQuery::class, HasRecordPageLayout::class);

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

function mergeDuplicateAction(): TestAction
{
    return TestAction::make('mergeDuplicate')->schemaComponent('duplicatePrompt', schema: 'infolist');
}

it('names a company that shares a domain and opens the merge with it picked', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Logistics'], ['domains' => ['WWW.Harborline.com']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee(__('filament/record-page.merge.duplicate.notice.company', ['name' => 'Harborline Logistics', 'value' => 'harborline.com']))
        ->mountAction(mergeDuplicateAction())
        ->assertSchemaStateSet(['duplicate_id' => $duplicate->getKey(), 'keep' => 'current'])
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($duplicate->fresh()->trashed())->toBeTrue();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertDontSee(__('filament/record-page.merge.duplicate.heading'));
});

it('names a person that shares an email in another case', function (): void {
    $person = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Reyes'], ['emails' => ['dana@harborline.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana R.'], ['emails' => ['other@harborline.com', 'Dana@Harborline.com']]);

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertSee(__('filament/record-page.merge.duplicate.notice.people', ['name' => 'Dana R.', 'value' => 'dana@harborline.com']))
        ->mountAction(mergeDuplicateAction())
        ->assertSchemaStateSet(['duplicate_id' => $duplicate->getKey()]);
});

it('shows no prompt without a live duplicate in the same workspace', function (string $case): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);

    match ($case) {
        'unshared' => mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.org']]),
        'trashed' => mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']])->delete(),
        'other workspace' => (function (): void {
            $other = User::factory()->withWorkspace()->create();
            TenantContextService::setTenantId($other->currentWorkspace->getKey());
            mergeCompanyRecord($other->currentWorkspace, $other, customFields: ['domains' => ['harborline.com']]);
            TenantContextService::setTenantId($this->workspace->getKey());
        })(),
    };

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertDontSee(__('filament/record-page.merge.duplicate.heading'));
})->with(['unshared', 'trashed', 'other workspace']);

it('hides the prompt from a member who cannot merge', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']]);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertDontSee(__('filament/record-page.merge.duplicate.heading'));
});

it('keeps the prompt for the duplicate that is left after a merge', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicates = collect(['Harborline One', 'Harborline Two'])
        ->map(fn (string $name): Company => mergeCompanyRecord($this->workspace, $this->user, ['name' => $name], ['domains' => ['harborline.com']]));

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->mountAction(mergeDuplicateAction())
        ->callMountedAction();

    $left = $duplicates->first(fn (Company $duplicate): bool => ! $duplicate->fresh()->trashed());

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee(__('filament/record-page.merge.duplicate.notice.company', ['name' => $left->name, 'value' => 'harborline.com']));
});
