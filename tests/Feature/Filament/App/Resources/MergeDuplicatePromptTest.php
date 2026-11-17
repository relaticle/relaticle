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

/**
 * @param  array<string, mixed>  $arguments
 */
function mergeDuplicateAction(string $name = 'mergeDuplicate', array $arguments = []): TestAction
{
    $component = $name === 'mergeDuplicate' ? 'duplicatePrompt.duplicateList' : 'duplicatePrompt';

    return TestAction::make($name)->arguments($arguments)->schemaComponent($component, schema: 'infolist');
}

it('names a company that shares a domain and opens the merge with it picked', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Logistics'], ['domains' => ['WWW.Harborline.com']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSeeText(trans_choice('filament/record-page.merge.duplicate.count', 1))
        ->assertSeeText('Harborline Logistics')
        ->assertSeeText(__('filament/record-page.merge.duplicate.shares', ['value' => 'harborline.com']))
        ->assertSeeHtml('aria-label="'.__('filament/record-page.merge.duplicate.merge_with', ['name' => 'Harborline Logistics']).'"')
        ->mountAction(mergeDuplicateAction(arguments: ['duplicate' => $duplicate->getKey()]))
        ->assertSchemaStateSet(['duplicate_id' => $duplicate->getKey(), 'keep' => 'current'])
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($duplicate->fresh()->trashed())->toBeTrue();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSchemaComponentHidden('duplicatePrompt', 'infolist');
});

it('names a person that shares an email in another case', function (): void {
    $person = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Reyes'], ['emails' => ['dana@harborline.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana R.'], ['emails' => ['other@harborline.com', 'Dana@Harborline.com']]);

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertSeeText('Dana R.')
        ->assertSeeText(__('filament/record-page.merge.duplicate.shares', ['value' => 'dana@harborline.com']))
        ->mountAction(mergeDuplicateAction(arguments: ['duplicate' => $duplicate->getKey()]))
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
        ->assertSchemaComponentHidden('duplicatePrompt', 'infolist');
})->with(['unshared', 'trashed', 'other workspace']);

it('hides the prompt from a member who cannot merge', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']]);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSchemaComponentHidden('duplicatePrompt', 'infolist');
});

it('keeps the prompt for the duplicate that is left after a merge', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicates = collect(['Harborline One', 'Harborline Two'])
        ->map(fn (string $name): Company => mergeCompanyRecord($this->workspace, $this->user, ['name' => $name], ['domains' => ['harborline.com']]));

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->mountAction(mergeDuplicateAction(arguments: ['duplicate' => $duplicates->first()->getKey()]))
        ->callMountedAction();

    expect($duplicates->first()->fresh()->trashed())->toBeTrue();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSeeText(trans_choice('filament/record-page.merge.duplicate.count', 1))
        ->assertSeeText('Harborline Two');
});

it('lists every record that matches any of its domains', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com', 'harborline.io']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline One'], ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Two'], ['domains' => ['harborline.io']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Three'], ['domains' => ['harborline.com', 'harborline.io']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSeeText(trans_choice('filament/record-page.merge.duplicate.count', 3))
        ->assertSeeTextInOrder(['Harborline One', 'Harborline Three', 'Harborline Two']);
});

it('opens the merge with the record of the row picked', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline One'], ['domains' => ['harborline.com']]);
    $second = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Two'], ['domains' => ['harborline.com']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->mountAction(mergeDuplicateAction(arguments: ['duplicate' => $second->getKey()]))
        ->assertSchemaStateSet(['duplicate_id' => $second->getKey()]);
});

it('picks the first duplicate when the row names a record that is not one', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline One'], ['domains' => ['harborline.com']]);
    $unrelated = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Northwind'], ['domains' => ['northwind.com']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->mountAction(mergeDuplicateAction(arguments: ['duplicate' => $unrelated->getKey()]))
        ->assertSchemaStateSet(['duplicate_id' => $duplicate->getKey()]);
});

it('hides the prompt on the dismissed record only', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Logistics'], ['domains' => ['harborline.com']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction(mergeDuplicateAction('dismissDuplicate'))
        ->assertSchemaComponentHidden('duplicatePrompt', 'infolist');

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSchemaComponentHidden('duplicatePrompt', 'infolist');

    livewire(ViewCompany::class, ['record' => $duplicate->getKey()])
        ->assertSchemaComponentVisible('duplicatePrompt', 'infolist');
});
