<?php

declare(strict_types=1);

use App\Actions\Merge\CombineRecordValues;
use App\Actions\Merge\MergeRecords;
use App\Actions\Merge\MoveRecordHistory;
use App\Actions\Merge\MoveRecordLinks;
use App\Actions\Onboarding\RemoveSampleData;
use App\ActivityLog\AppEventPalette;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\MediaCollection;
use App\Enums\RecordIdentity;
use App\Enums\WorkspaceRole;
use App\Events\RecordMerged;
use App\Filament\Actions\MergeRecordAction;
use App\Filament\Components\Forms\MergeRecordPicker;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ActivityLog\MergedActivityRenderer;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\WorkspaceCustomField;

mutates(MergeRecords::class, CombineRecordValues::class, MoveRecordLinks::class, MoveRecordHistory::class, MergeRecordAction::class, MergeRecordPicker::class, RecordIdentity::class, CrmEntity::class, MergedActivityRenderer::class, AppEventPalette::class);

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

function mergeCompanyValue(Company $company, string $code): mixed
{
    $company = Company::withTrashed()->with('customFieldValues.customField')->findOrFail($company->getKey());

    return $company->getCustomFieldValue(WorkspaceCustomField::byCode($company->workspace_id, 'company', $code));
}

function mergeWith(Company $current, Company $picked, bool $keepPicked = false): mixed
{
    return livewire(ViewCompany::class, ['record' => $current->getKey()])
        ->callAction(mergeRecordAction(), data: ['picked_id' => $picked->getKey(), 'keep_picked' => $keepPicked]);
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

it('creates a new company from both and fills the current company\'s empty values from the other', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Brightline Health'], ['domains' => ['brightlinehealth.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Brightline Health Inc.'], ['icp' => true, 'domains' => ['brightline.health', 'brightlinehealth.com']]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    $merged = mergedRecord();

    expect($merged->getKey())->not->toBeIn([$kept->getKey(), $duplicate->getKey()])
        ->and($merged->name)->toBe('Brightline Health')
        ->and(mergeCompanyValue($merged, 'icp'))->toBeTrue()
        ->and(mergeCompanyValue($merged, 'domains'))->toBe(['brightlinehealth.com', 'brightline.health'])
        ->and(Company::withTrashed()->find($kept->getKey())->trashed())->toBeTrue()
        ->and(Company::withTrashed()->find($duplicate->getKey())->trashed())->toBeTrue();
});

it('gives the new company the current company\'s creator and source, and no onboarding turn', function (): void {
    $creator = User::factory()->create();
    $this->workspace->users()->attach($creator, ['role' => WorkspaceRole::Member->value]);
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['creation_source' => CreationSource::MCP, 'creator_id' => $creator->getKey()]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['creation_source' => CreationSource::WEB]);
    Cache::flush();

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergedRecord()->creator_id)->toBe($creator->getKey())
        ->and(mergedRecord()->creation_source)->toBe(CreationSource::MCP)
        ->and(Cache::has("chat:assistant-arrival-scheduled:{$this->workspace->getKey()}"))->toBeFalse();
});

it('lets the other company win when the member swaps the two', function (): void {
    $current = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Northwind'], ['domains' => ['northwind.com']]);
    $other = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Northwind Traders'], ['domains' => ['northwindtraders.com']]);

    $page = mergeWith($current, $other, keepPicked: true)->assertHasNoActionErrors();
    $merged = mergedRecord();

    $page->assertRedirect(CompanyResource::getUrl('view', ['record' => $merged]));

    expect($merged->name)->toBe('Northwind Traders')
        ->and(Company::withTrashed()->find($current->getKey())->trashed())->toBeTrue()
        ->and(Company::withTrashed()->find($other->getKey())->trashed())->toBeTrue()
        ->and(mergeCompanyValue($merged, 'domains'))->toBe(['northwindtraders.com', 'northwind.com']);
});

it('redirects to the new company after a merge', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);

    $page = mergeWith($kept, $duplicate);

    $page->assertRedirect(CompanyResource::getUrl('view', ['record' => mergedRecord()]));
});

it('never overwrites a filled value with an empty one', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, [], ['linkedin' => ['https://linkedin.com/company/kept']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergeCompanyValue(mergedRecord(), 'linkedin'))->toBe(['https://linkedin.com/company/kept']);
});

it('keeps the current company\'s value where both hold one', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, [], ['linkedin' => ['https://linkedin.com/company/kept']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, [], ['linkedin' => ['https://linkedin.com/company/duplicate']]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergeCompanyValue(mergedRecord(), 'linkedin'))->toBe(['https://linkedin.com/company/kept']);
});

it('combines multi-select values with the kept record first', function (): void {
    $field = mergeMultiSelect($this->workspace);
    $enterprise = $field->options()->where('name', 'Enterprise')->value('id');
    $eu = $field->options()->where('name', 'EU')->value('id');
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $kept->saveCustomFieldValue($field, [$enterprise]);
    $duplicate->saveCustomFieldValue($field, [$eu, $enterprise]);

    mergeWith($kept, $duplicate);

    expect(mergeCompanyValue(mergedRecord(), 'segments'))->toBe([$enterprise, $eu]);
});

it('hides merge from a member who cannot create, update and delete records', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(ViewCompany::class, ['record' => $company->getKey()])->assertActionHidden(mergeRecordAction());
});

it('offers no merge to a member who may create and delete records but not update them', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);

    livewire(ViewCompany::class, ['record' => $kept->getKey()])->assertActionHidden(mergeRecordAction());

    expect(fn (): mixed => resolve(MergeRecords::class)->execute($this->user, $kept, $duplicate))->toThrow(HttpException::class);

    expect(Company::query()->count())->toBe(2);
});

it('refuses the merge when the member loses merge rights while the dialog is open', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline']);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Logistics']);
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->switchWorkspace($this->workspace);
    $this->actingAs($admin);

    $dialog = livewire(ViewCompany::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $duplicate->getKey(), 'keep_picked' => false]);

    $this->workspace->users()->updateExistingPivot($admin->getKey(), ['role' => WorkspaceRole::Viewer->value]);
    $this->actingAs($admin->fresh());

    $dialog->callMountedAction();

    expect(Company::query()->find($duplicate->getKey()))->not->toBeNull()
        ->and($kept->refresh()->name)->toBe('Harborline')
        ->and(Activity::query()->where('event', 'record_merged')->exists())->toBeFalse();
});

it('offers merge to a member who can create, update and delete records', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user);

    livewire(ViewCompany::class, ['record' => $company->getKey()])->assertActionVisible(mergeRecordAction());
});

it('refuses the current record, a trashed record and another workspace\'s record', function (string $case): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept']);
    $foreignOwner = User::factory()->withWorkspace()->create();
    $forged = match ($case) {
        'itself' => $kept,
        'trashed' => tap(mergeCompanyRecord($this->workspace, $this->user), fn (Company $company) => $company->delete()),
        'other workspace' => Company::factory()->recycle([$foreignOwner, $foreignOwner->currentWorkspace])->create(),
    };

    mergeWith($kept, $forged)->assertHasActionErrors(['picked_id']);

    expect(Company::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(Company::withTrashed()->withoutGlobalScopes()->find($forged->getKey())->trashed())->toBe($case === 'trashed');
})->with(['itself', 'trashed', 'other workspace']);

it('blocks a merge that would give a company more than five domains', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, [], ['domains' => ['a1.com', 'a2.com', 'a3.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, [], ['domains' => ['b1.com', 'b2.com', 'b3.com']]);

    mergeWith($kept, $duplicate)->assertHasActionErrors(['picked_id']);

    expect(Company::query()->whereKey($duplicate->getKey())->exists())->toBeTrue()
        ->and(Company::query()->count())->toBe(2)
        ->and(mergeCompanyValue($kept, 'domains'))->toHaveCount(3);
});

it('blocks a merge over a field\'s own maximum and says one value when the limit is one', function (): void {
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'regions',
        'name' => 'Regions',
        'type' => 'multi-select',
        'settings' => new CustomFieldSettingsData(allow_multiple: true, max_values: 1),
    ]);
    $options = collect(['NA', 'EU'])->map(fn (string $name, int $index): string => CustomFieldOption::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_id' => $field->getKey(),
        'name' => $name,
        'sort_order' => $index + 1,
    ])->getKey());
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $kept->saveCustomFieldValue($field, [$options[0]]);
    $duplicate->saveCustomFieldValue($field, [$options[1]]);

    mergeWith($kept, $duplicate)->assertHasActionErrors([
        'picked_id' => 'Regions can hold at most 1. Together these records have more. Remove some first.',
    ]);

    expect(Company::query()->whereKey($duplicate->getKey())->exists())->toBeTrue();
});

it('leaves another workspace\'s rows alone even when they point at an original', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $foreignOwner = User::factory()->withWorkspace()->create();
    $foreignPerson = People::factory()->recycle([$foreignOwner, $foreignOwner->currentWorkspace])->create();
    $foreignOpportunity = Opportunity::factory()->recycle([$foreignOwner, $foreignOwner->currentWorkspace])->create();
    expect([$foreignPerson->workspace_id, $foreignOpportunity->workspace_id])->each->not->toBe($this->workspace->getKey());
    DB::table('people')->where('id', $foreignPerson->getKey())->update(['company_id' => $duplicate->getKey()]);
    DB::table('opportunities')->where('id', $foreignOpportunity->getKey())->update(['company_id' => $duplicate->getKey()]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(DB::table('people')->where('id', $foreignPerson->getKey())->value('company_id'))->toBe($duplicate->getKey())
        ->and(DB::table('opportunities')->where('id', $foreignOpportunity->getKey())->value('company_id'))->toBe($duplicate->getKey());
});

it('bumps updated_at on repointed people and opportunities', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $duplicate->getKey()]);
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $duplicate->getKey()]);
    $personTouchedAt = $person->refresh()->updated_at;
    $opportunityTouchedAt = $opportunity->refresh()->updated_at;
    $this->travelTo(now()->addHour());

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($person->refresh()->updated_at->greaterThan($personTouchedAt))->toBeTrue()
        ->and($opportunity->refresh()->updated_at->greaterThan($opportunityTouchedAt))->toBeTrue();
});

it('makes the new company real data when the other company was real', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['creation_source' => CreationSource::SAMPLE]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['creation_source' => CreationSource::WEB]);

    mergeWith($kept, $duplicate);
    $merged = mergedRecord();

    expect($merged->creation_source)->toBe(CreationSource::WEB);

    resolve(RemoveSampleData::class)->execute($this->user, $this->workspace);

    expect(Company::query()->whereKey($merged->getKey())->exists())->toBeTrue();
});

it('shows the unavailable error when the duplicate is deleted after it was picked in the dialog', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept'], ['icp' => true]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, [], ['linkedin' => ['https://linkedin.com/company/gone']]);

    $page = livewire(ViewCompany::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $duplicate->getKey(), 'keep_picked' => false]);
    $duplicate->delete();
    $page->callMountedAction()->assertHasActionErrors(['picked_id' => __('filament/record-page.merge.unavailable')]);

    expect(mergeCompanyValue($kept, 'linkedin'))->toBeNull()
        ->and($kept->refresh()->name)->toBe('Kept');
});

it('shows the unavailable error when the duplicate disappears while the merge starts', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept']);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);

    $page = livewire(ViewCompany::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $duplicate->getKey(), 'keep_picked' => false]);
    Event::listen(TransactionBeginning::class, fn (): int => DB::table('companies')->where('id', $duplicate->getKey())->update(['deleted_at' => now()]));
    $page->callMountedAction()->assertHasActionErrors(['picked_id' => __('filament/record-page.merge.unavailable')]);

    expect(Company::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(Company::withTrashed()->count())->toBe(2)
        ->and(Activity::query()->where('subject_id', $kept->getKey())->where('event', 'record_merged')->exists())->toBeFalse();
});

it('shows the unavailable error when the kept company disappears while the merge starts', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept']);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $duplicate->notes()->attach($note->getKey());

    $page = livewire(ViewCompany::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $duplicate->getKey(), 'keep_picked' => false]);
    Event::listen(TransactionBeginning::class, fn (): int => DB::table('companies')->where('id', $kept->getKey())->update(['deleted_at' => now()]));
    $page->callMountedAction()->assertHasActionErrors(['picked_id' => __('filament/record-page.merge.unavailable')]);

    expect(Company::query()->find($duplicate->getKey()))->not->toBeNull()
        ->and(Company::withTrashed()->count())->toBe(2)
        ->and(mergeLinkedIds('noteables', 'note_id', $duplicate))->toBe([$note->getKey()])
        ->and(Activity::query()->where('event', 'record_merged')->exists())->toBeFalse();
});

it('leaves the owner empty when the duplicate\'s owner is not a workspace member', function (): void {
    $stranger = User::factory()->create();
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['account_owner_id' => null]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['account_owner_id' => $stranger->getKey()]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergedRecord()->account_owner_id)->toBeNull();
});

it('takes the duplicate\'s owner when the kept company has none and the owner is still a member', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['account_owner_id' => null]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['account_owner_id' => $this->user->getKey()]);

    mergeWith($kept, $duplicate);

    expect(mergedRecord()->account_owner_id)->toBe($this->user->getKey());
});

it('drops the kept company\'s owner who left the workspace and takes the duplicate\'s member owner', function (bool $duplicateHasOwner): void {
    $departed = User::factory()->create();
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['account_owner_id' => $departed->getKey()]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['account_owner_id' => $duplicateHasOwner ? $this->user->getKey() : null]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergedRecord()->account_owner_id)->toBe($duplicateHasOwner ? $this->user->getKey() : null);
})->with([true, false]);

it('rolls back the new company and every moved link when the final write fails validation', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['account_owner_id' => $member->getKey()]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $duplicate->notes()->attach($note->getKey());
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $duplicate->tasks()->attach($task->getKey());
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $duplicate->getKey()]);
    $logo = mergeMedia($duplicate, Company::LOGO_MEDIA_COLLECTION);
    activity()->performedOn($kept)->event('record_merged')->withProperties(['merged_names' => ['Brightline', 'Brightline Health']])->log('record_merged');

    Event::listen(RecordMerged::class, fn (): int => $this->workspace->users()->detach($member));
    mergeWith($kept, $duplicate)->assertHasActionErrors(['picked_id']);

    expect(Company::query()->find($duplicate->getKey()))->not->toBeNull()
        ->and(Company::withTrashed()->count())->toBe(2)
        ->and(DB::table('noteables')->where('note_id', $note->getKey())->pluck('noteable_id')->all())->toBe([$duplicate->getKey()])
        ->and(DB::table('taskables')->where('task_id', $task->getKey())->pluck('taskable_id')->all())->toBe([$duplicate->getKey()])
        ->and($person->refresh()->company_id)->toBe($duplicate->getKey())
        ->and($logo->refresh()->model_id)->toBe($duplicate->getKey())
        ->and(Activity::query()->where('event', 'record_merged')->pluck('subject_id')->all())->toBe([$kept->getKey()]);
});

it('finds a company by name or by any of its domains', function (string $term, array $found): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept']);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Brightline Health'], ['domains' => ['brightline.health', 'brightlinehealth.com']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Northwind'], ['domains' => ['northwind.com']]);

    expect(mergeSearchNames(ViewCompany::class, $kept, $term))->toBe($found);
})->with([
    'name' => ['Bright', ['Brightline Health']],
    'second domain' => ['brightlinehealth', ['Brightline Health']],
    'domain fragment' => ['northwind.c', ['Northwind']],
    'nothing' => ['zzz', []],
]);

it('leaves the current company, a trashed company and another workspace\'s company out of the search', function (): void {
    $foreignOwner = User::factory()->withWorkspace()->create();
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Brightline Current']);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Brightline Live']);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Brightline Trashed'])->delete();
    mergeCompanyRecord($foreignOwner->currentWorkspace, $foreignOwner, ['name' => 'Brightline Foreign']);

    expect(mergeSearchNames(ViewCompany::class, $kept, 'Brightline'))->toBe(['Brightline Live']);
});

it('finds a company by domain while the domains column is hidden from the list', function (): void {
    $domains = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'domains');
    $settings = $domains->settings;
    $settings->visible_in_list = false;
    $domains->settings = $settings;
    $domains->save();
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept']);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Brightline Health'], ['domains' => ['brightline.health']]);

    expect(mergeSearchNames(ViewCompany::class, $kept, 'brightline.health'))->toBe(['Brightline Health']);
});

it('shows no domains in the search once the domains attribute is deactivated, like the duplicate prompt', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept']);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Brightline Health'], ['domains' => ['brightline.health']]);

    expect(array_column(mergeSearch(ViewCompany::class, $kept, 'Bright')->effects['returns'][0], 'detail'))->toBe(['brightline.health']);

    WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'domains')->update(['active' => false]);

    expect(array_column(mergeSearch(ViewCompany::class, $kept, 'Bright')->effects['returns'][0], 'detail'))->toBe([null]);
});

it('searches for a duplicate without rendering the dialog again', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept']);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Northwind']);

    $effects = mergeSearch(ViewCompany::class, $kept, 'North')->effects;

    expect($effects)->not->toHaveKey('html')
        ->and($effects['partials'] ?? [])->toBe([]);
});

it('keeps the current company again when the member picks a different duplicate after a swap', function (): void {
    $current = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Kept']);
    $first = mergeCompanyRecord($this->workspace, $this->user);
    $second = mergeCompanyRecord($this->workspace, $this->user);

    livewire(ViewCompany::class, ['record' => $current->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $first->getKey(), 'keep_picked' => true])
        ->set('mountedActions.0.data.picked_id', $second->getKey())
        ->assertSet('mountedActions.0.data.keep_picked', false);
});

it('moves both companies\' notes and tasks and keeps a shared one once', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    [$onlyDuplicate, $shared, $onlyKept] = Note::factory()->count(3)->recycle([$this->user, $this->workspace])->create()->all();
    [$taskOnDuplicate, $sharedTask] = Task::factory()->count(2)->recycle([$this->user, $this->workspace])->create()->all();
    $duplicate->notes()->attach([$onlyDuplicate->getKey(), $shared->getKey()]);
    $kept->notes()->attach([$shared->getKey(), $onlyKept->getKey()]);
    $duplicate->tasks()->attach([$taskOnDuplicate->getKey(), $sharedTask->getKey()]);
    $kept->tasks()->attach($sharedTask->getKey());

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();
    $merged = mergedRecord();

    expect(mergeLinkedIds('noteables', 'note_id', $merged))->toBe(collect([$onlyDuplicate, $shared, $onlyKept])->map->getKey()->sort()->values()->all())
        ->and(mergeLinkedIds('taskables', 'task_id', $merged))->toBe(collect([$taskOnDuplicate, $sharedTask])->map->getKey()->sort()->values()->all())
        ->and(mergeLinkedIds('noteables', 'note_id', $kept))->toBe([])
        ->and(mergeLinkedIds('taskables', 'task_id', $kept))->toBe([])
        ->and(mergeLinkedIds('noteables', 'note_id', $duplicate))->toBe([])
        ->and(mergeLinkedIds('taskables', 'task_id', $duplicate))->toBe([]);
});

it('copies a rich-text value with its image from the duplicate', function (): void {
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'brief',
        'name' => 'Brief',
        'type' => 'rich-editor',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $image = mergeMedia($duplicate, MediaCollection::Attachments->value);
    $html = "<p>Notes</p><p><img data-id=\"{$image->uuid}\" src=\"stale\"></p>";
    $duplicate->saveCustomFieldValue($field, $html);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    $image = Media::query()->where('uuid', $image->uuid)->firstOrFail();

    $merged = mergedRecord();

    expect(mergeCompanyValue($merged, 'brief'))->toContain($image->uuid)
        ->and($image->model_id)->toBe($merged->getKey())
        ->and($image->collection_name)->toBe(MediaCollection::Attachments->value);
});

it('repoints record-type attributes on other records to the new company', function (): void {
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'opportunity',
        'code' => 'partner',
        'name' => 'Partner',
        'type' => 'record',
        'lookup_type' => 'company',
        'settings' => new CustomFieldSettingsData(allow_multiple: true),
    ]);
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $other = mergeCompanyRecord($this->workspace, $this->user);
    $both = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $onlyDuplicate = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $unrelated = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $both->saveCustomFieldValue($field, [$duplicate->getKey(), $kept->getKey()]);
    $onlyDuplicate->saveCustomFieldValue($field, [$other->getKey(), $duplicate->getKey()]);
    $unrelated->saveCustomFieldValue($field, [$other->getKey()]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    $read = fn (Opportunity $opportunity): mixed => Opportunity::query()->with('customFieldValues.customField')->findOrFail($opportunity->getKey())->getCustomFieldValue($field);
    $merged = mergedRecord();

    expect($read($both))->toBe([$merged->getKey()])
        ->and($read($onlyDuplicate))->toBe([$other->getKey(), $merged->getKey()])
        ->and($read($unrelated))->toBe([$other->getKey()]);
});

it('repoints a record-type attribute that also holds a non-string element', function (): void {
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'opportunity',
        'code' => 'partner',
        'name' => 'Partner',
        'type' => 'record',
        'lookup_type' => 'company',
        'settings' => new CustomFieldSettingsData(allow_multiple: true),
    ]);
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($field, [$duplicate->getKey()]);
    DB::table('custom_field_values')
        ->where('custom_field_id', $field->getKey())
        ->where('entity_id', $opportunity->getKey())
        ->update(['json_value' => json_encode([123, null, $duplicate->getKey()])]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    $stored = DB::table('custom_field_values')->where('custom_field_id', $field->getKey())->where('entity_id', $opportunity->getKey())->value('json_value');

    expect(json_decode((string) $stored, true))->toBe([123, null, mergedRecord()->getKey()]);
});

it('points the new company\'s record attributes at itself where an original named the other', function (): void {
    foreach (['related_companies' => true, 'parent_company' => false] as $code => $allowMultiple) {
        CustomField::factory()->create([
            'tenant_id' => $this->workspace->getKey(),
            'entity_type' => 'company',
            'code' => $code,
            'name' => str($code)->headline()->toString(),
            'type' => 'record',
            'lookup_type' => 'company',
            'settings' => new CustomFieldSettingsData(allow_multiple: $allowMultiple),
        ]);
    }
    $partner = mergeCompanyRecord($this->workspace, $this->user);
    $supplier = mergeCompanyRecord($this->workspace, $this->user);
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $kept->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'related_companies'), [$duplicate->getKey(), $partner->getKey()]);
    $kept->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'parent_company'), [$duplicate->getKey()]);
    $duplicate->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'related_companies'), [$kept->getKey(), $supplier->getKey()]);
    $duplicate->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'parent_company'), [$kept->getKey()]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();
    $merged = mergedRecord();

    expect(mergeCompanyValue($merged, 'related_companies'))->toBe([$merged->getKey(), $partner->getKey(), $supplier->getKey()])
        ->and(mergeCompanyValue($merged, 'parent_company'))->toBe([$merged->getKey()]);
});

it('moves the people and opportunities to the new company', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $duplicate->getKey()]);
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $duplicate->getKey()]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($person->refresh()->company_id)->toBe(mergedRecord()->getKey())
        ->and($opportunity->refresh()->company_id)->toBe(mergedRecord()->getKey());
});

it('takes the other company\'s logo only when the current company has none', function (bool $keptHasLogo): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $keptLogo = $keptHasLogo ? mergeMedia($kept, Company::LOGO_MEDIA_COLLECTION) : null;
    $duplicateLogo = mergeMedia($duplicate, Company::LOGO_MEDIA_COLLECTION);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    $logos = Media::query()->where('model_type', 'company')->where('collection_name', Company::LOGO_MEDIA_COLLECTION)->get();

    expect($logos->where('model_id', mergedRecord()->getKey())->pluck('id')->all())->toBe([($keptLogo ?? $duplicateLogo)->getKey()])
        ->and($logos->where('model_id', $kept->getKey())->pluck('id')->all())->toBe([])
        ->and($logos->where('model_id', $duplicate->getKey())->pluck('id')->all())->toBe($keptHasLogo ? [$duplicateLogo->getKey()] : []);
})->with([true, false]);

it('offers no merge and no duplicate prompt on a deleted company', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']]);
    $company->delete();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionHidden(mergeRecordAction())
        ->assertSchemaComponentHidden('duplicatePrompt', 'infolist');
});

it('keeps the image of the current company\'s rich-text value when it wins the field', function (): void {
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'brief',
        'name' => 'Brief',
        'type' => 'rich-editor',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $kept = mergeCompanyRecord($this->workspace, $this->user);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user);
    $keptImage = mergeMedia($kept, MediaCollection::Attachments->value);
    $duplicateImage = mergeMedia($duplicate, MediaCollection::Attachments->value);
    $kept->saveCustomFieldValue($field, "<p>Kept</p><p><img data-id=\"{$keptImage->uuid}\" src=\"stale\"></p>");
    $duplicate->saveCustomFieldValue($field, "<p>Duplicate</p><p><img data-id=\"{$duplicateImage->uuid}\" src=\"stale\"></p>");

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergeCompanyValue(mergedRecord(), 'brief'))->toContain($keptImage->uuid)
        ->and(Media::query()->where('uuid', $keptImage->uuid)->value('model_id'))->toBe(mergedRecord()->getKey())
        ->and(Media::query()->where('uuid', $duplicateImage->uuid)->value('model_id'))->toBe($duplicate->getKey());
});

it('counts domains the way the field stores them before applying the limit', function (): void {
    $kept = mergeCompanyRecord($this->workspace, $this->user, [], ['domains' => ['a1.com', 'a2.com', 'a3.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, [], ['domains' => ['A1.com', 'https://www.a2.com/about', 'a3.com', 'b1.com', 'b2.com']]);

    mergeWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergeCompanyValue(mergedRecord(), 'domains'))->toBe(['a1.com', 'a2.com', 'a3.com', 'b1.com', 'b2.com']);
});

it('locks both companies with one select in id order, whichever one is kept', function (): void {
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'for update') && str_contains($query->sql, ' in (')) {
            $locks[] = $query;
        }
    });

    foreach (['current' => false, 'picked' => true] as $kept => $keepPicked) {
        $locks = [];
        $current = mergeCompanyRecord($this->workspace, $this->user);
        $other = mergeCompanyRecord($this->workspace, $this->user);

        mergeWith($current, $other, $keepPicked)->assertHasNoActionErrors();

        expect($locks)->toHaveCount(1, $kept)
            ->and($locks[0]->sql)->toStartWith('select * from "companies"')
            ->and($locks[0]->sql)->toEndWith('order by "id" asc for update')
            ->and($locks[0]->bindings)->toContain($current->getKey(), $other->getKey());
    }
});
