<?php

declare(strict_types=1);

use App\Actions\Merge\CombineRecordValues;
use App\Actions\Merge\MergeCompanies;
use App\Actions\Onboarding\RemoveSampleData;
use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
use App\Filament\Actions\MergeRecordAction;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Helpers\WorkspaceCustomField;

mutates(MergeCompanies::class, CombineRecordValues::class, MergeRecordAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function mergeAction(): TestAction
{
    return TestAction::make('mergeRecord')->schemaComponent('recordActions', schema: 'infolist');
}

function mergeCompany(Workspace $workspace, User $owner, array $attributes = [], array $customFields = []): Company
{
    $company = Company::factory()->recycle([$owner, $workspace])->create($attributes);

    foreach ($customFields as $code => $value) {
        $company->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), 'company', $code), $value);
    }

    return $company;
}

function mergeCompanyValue(Company $company, string $code): mixed
{
    $company = Company::withTrashed()->with('customFieldValues.customField')->findOrFail($company->getKey());

    return $company->getCustomFieldValue(WorkspaceCustomField::byCode($company->workspace_id, 'company', $code));
}

function mergeWith(Company $kept, Company $duplicate, string $keep = 'current'): mixed
{
    return livewire(ViewCompany::class, ['record' => $kept->getKey()])
        ->callAction(mergeAction(), data: ['duplicate_id' => $duplicate->getKey(), 'keep' => $keep]);
}

function mergeMultiSelect(Workspace $workspace): CustomField
{
    $field = CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'segments',
        'name' => 'Segments',
        'type' => 'multi-select',
        'settings' => new CustomFieldSettingsData(allow_multiple: true, max_values: 10),
    ]);

    foreach (['Enterprise', 'EU'] as $index => $name) {
        CustomFieldOption::query()->create([
            'tenant_id' => $workspace->getKey(),
            'custom_field_id' => $field->getKey(),
            'name' => $name,
            'sort_order' => $index + 1,
        ]);
    }

    return $field;
}

it('keeps the current company and fills its empty values from the duplicate', function (): void {
    $kept = mergeCompany($this->workspace, $this->user, ['name' => 'Brightline Health'], ['domains' => ['brightlinehealth.com']]);
    $duplicate = mergeCompany($this->workspace, $this->user, ['name' => 'Brightline Health Inc.'], ['icp' => true, 'domains' => ['brightline.health', 'brightlinehealth.com']]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($kept->refresh()->name)->toBe('Brightline Health')
        ->and(mergeCompanyValue($kept, 'icp'))->toBeTrue()
        ->and(mergeCompanyValue($kept, 'domains'))->toBe(['brightlinehealth.com', 'brightline.health'])
        ->and(Company::withTrashed()->find($duplicate->getKey())->trashed())->toBeTrue()
        ->and(Company::query()->find($kept->getKey()))->not->toBeNull();
});

it('keeps the other company when the member swaps the two', function (): void {
    $current = mergeCompany($this->workspace, $this->user, ['name' => 'Northwind'], ['domains' => ['northwind.com']]);
    $other = mergeCompany($this->workspace, $this->user, ['name' => 'Northwind Traders'], ['domains' => ['northwindtraders.com']]);

    mergeWith($current, $other, 'other')
        ->assertHasNoActionErrors()
        ->assertRedirect(CompanyResource::getUrl('view', ['record' => $other]));

    expect(Company::query()->find($other->getKey())->name)->toBe('Northwind Traders')
        ->and(Company::withTrashed()->find($current->getKey())->trashed())->toBeTrue()
        ->and(mergeCompanyValue($other, 'domains'))->toBe(['northwindtraders.com', 'northwind.com']);
});

it('redirects to the kept company after a merge', function (): void {
    $kept = mergeCompany($this->workspace, $this->user);
    $duplicate = mergeCompany($this->workspace, $this->user);

    mergeWith($kept, $duplicate)->assertRedirect(CompanyResource::getUrl('view', ['record' => $kept]));
});

it('never overwrites a filled value with an empty one', function (): void {
    $kept = mergeCompany($this->workspace, $this->user, [], ['linkedin' => ['https://linkedin.com/company/kept']]);
    $duplicate = mergeCompany($this->workspace, $this->user);

    mergeWith($kept, $duplicate);

    expect(mergeCompanyValue($kept, 'linkedin'))->toBe(['https://linkedin.com/company/kept']);
});

it('keeps the value of the kept company where both hold one', function (): void {
    $kept = mergeCompany($this->workspace, $this->user, [], ['linkedin' => ['https://linkedin.com/company/kept']]);
    $duplicate = mergeCompany($this->workspace, $this->user, [], ['linkedin' => ['https://linkedin.com/company/duplicate']]);

    mergeWith($kept, $duplicate);

    expect(mergeCompanyValue($kept, 'linkedin'))->toBe(['https://linkedin.com/company/kept']);
});

it('combines multi-select values with the kept record first', function (): void {
    $field = mergeMultiSelect($this->workspace);
    $enterprise = $field->options()->where('name', 'Enterprise')->value('id');
    $eu = $field->options()->where('name', 'EU')->value('id');
    $kept = mergeCompany($this->workspace, $this->user);
    $duplicate = mergeCompany($this->workspace, $this->user);
    $kept->saveCustomFieldValue($field, [$enterprise]);
    $duplicate->saveCustomFieldValue($field, [$eu, $enterprise]);

    mergeWith($kept, $duplicate);

    expect(mergeCompanyValue($kept, 'segments'))->toBe([$enterprise, $eu]);
});

it('hides merge from a member who cannot update and delete the record', function (): void {
    $company = mergeCompany($this->workspace, $this->user);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(ViewCompany::class, ['record' => $company->getKey()])->assertActionHidden(mergeAction());
});

it('offers merge to a member who can update and delete the record', function (): void {
    $company = mergeCompany($this->workspace, $this->user);

    livewire(ViewCompany::class, ['record' => $company->getKey()])->assertActionVisible(mergeAction());
});

it('refuses the current record, a trashed record and another workspace\'s record', function (string $case): void {
    $kept = mergeCompany($this->workspace, $this->user, ['name' => 'Kept']);
    $foreignOwner = User::factory()->withWorkspace()->create();
    $forged = match ($case) {
        'itself' => $kept,
        'trashed' => tap(mergeCompany($this->workspace, $this->user), fn (Company $company) => $company->delete()),
        'other workspace' => Company::factory()->recycle([$foreignOwner, $foreignOwner->currentWorkspace])->create(),
    };

    mergeWith($kept, $forged)->assertHasActionErrors(['duplicate_id']);

    expect(Company::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(Company::withTrashed()->withoutGlobalScopes()->find($forged->getKey())->trashed())->toBe($case === 'trashed');
})->with(['itself', 'trashed', 'other workspace']);

it('blocks a merge that would give a company more than five domains', function (): void {
    $kept = mergeCompany($this->workspace, $this->user, [], ['domains' => ['a1.com', 'a2.com', 'a3.com']]);
    $duplicate = mergeCompany($this->workspace, $this->user, [], ['domains' => ['b1.com', 'b2.com', 'b3.com']]);

    mergeWith($kept, $duplicate)->assertHasActionErrors(['duplicate_id']);

    expect(Company::query()->whereKey($duplicate->getKey())->exists())->toBeTrue()
        ->and(mergeCompanyValue($kept, 'domains'))->toHaveCount(3);
});

it('makes the kept company real data when the duplicate was real', function (): void {
    $kept = mergeCompany($this->workspace, $this->user, ['creation_source' => CreationSource::SAMPLE]);
    $duplicate = mergeCompany($this->workspace, $this->user, ['creation_source' => CreationSource::WEB]);

    mergeWith($kept, $duplicate);

    expect($kept->refresh()->creation_source)->toBe(CreationSource::WEB);

    resolve(RemoveSampleData::class)->execute($this->user, $this->workspace);

    expect(Company::query()->whereKey($kept->getKey())->exists())->toBeTrue();
});

it('refuses a duplicate deleted after the page opened', function (): void {
    $kept = mergeCompany($this->workspace, $this->user, ['name' => 'Kept'], ['icp' => true]);
    $duplicate = mergeCompany($this->workspace, $this->user, [], ['linkedin' => ['https://linkedin.com/company/gone']]);

    $page = livewire(ViewCompany::class, ['record' => $kept->getKey()]);
    $duplicate->delete();
    $page->callAction(mergeAction(), data: ['duplicate_id' => $duplicate->getKey(), 'keep' => 'current'])
        ->assertHasActionErrors(['duplicate_id']);

    expect(mergeCompanyValue($kept, 'linkedin'))->toBeNull()
        ->and($kept->refresh()->name)->toBe('Kept');
});

it('refuses a duplicate deleted between the lookup and the merge', function (): void {
    $kept = mergeCompany($this->workspace, $this->user);
    $duplicate = mergeCompany($this->workspace, $this->user);
    $stale = Company::query()->findOrFail($duplicate->getKey());
    $duplicate->delete();

    expect(fn () => resolve(MergeCompanies::class)->execute($this->user, $kept, $stale))
        ->toThrow(NotFoundHttpException::class);
});

it('leaves the owner empty when the duplicate\'s owner is not a workspace member', function (): void {
    $stranger = User::factory()->create();
    $kept = mergeCompany($this->workspace, $this->user, ['account_owner_id' => null]);
    $duplicate = mergeCompany($this->workspace, $this->user, ['account_owner_id' => $stranger->getKey()]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($kept->refresh()->account_owner_id)->toBeNull()
        ->and(Company::withTrashed()->find($duplicate->getKey())->trashed())->toBeTrue();
});

it('takes the duplicate\'s owner when the kept company has none and the owner is still a member', function (): void {
    $kept = mergeCompany($this->workspace, $this->user, ['account_owner_id' => null]);
    $duplicate = mergeCompany($this->workspace, $this->user, ['account_owner_id' => $this->user->getKey()]);

    mergeWith($kept, $duplicate);

    expect($kept->refresh()->account_owner_id)->toBe($this->user->getKey());
});
