<?php

declare(strict_types=1);

use App\Actions\Merge\CombineRecordValues;
use App\Actions\Merge\LogRecordMerge;
use App\Actions\Merge\MergeRecords;
use App\Actions\Merge\MoveMorphPivot;
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
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityLog\MergedActivityRenderer;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Helpers\WorkspaceCustomField;

mutates(MergeRecords::class, CombineRecordValues::class, MoveRecordLinks::class, LogRecordMerge::class, MoveMorphPivot::class, MergeRecordAction::class, MergeRecordPicker::class, RecordIdentity::class, CrmEntity::class, MergedActivityRenderer::class, AppEventPalette::class);

beforeEach(function (): void {
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

function mergePersonValue(People $person, string $code): mixed
{
    $person = People::withTrashed()->with('customFieldValues.customField')->findOrFail($person->getKey());

    return $person->getCustomFieldValue(WorkspaceCustomField::byCode($person->workspace_id, 'people', $code));
}

function mergePeopleWith(People $current, People $picked, bool $keepPicked = false): mixed
{
    return livewire(ViewPeople::class, ['record' => $current->getKey()])
        ->callAction(mergeRecordAction(), data: ['picked_id' => $picked->getKey(), 'keep_picked' => $keepPicked]);
}

it('rolls back the new person and every moved link when the final write fails validation', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $kept = mergePersonRecord($this->workspace, $this->user, ['company_id' => null]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['company_id' => $company->getKey()]);
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $duplicate->notes()->attach($note->getKey());
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $duplicate->tasks()->attach($task->getKey());
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $duplicate->getKey()]);
    activity()->performedOn($kept)->event('record_merged')->withProperties(['merged_name' => 'Dana'])->log('record_merged');

    Event::listen(RecordMerged::class, fn (): int => DB::table('companies')->where('id', $company->getKey())->update(['deleted_at' => now()]));
    mergePeopleWith($kept, $duplicate)->assertHasActionErrors(['picked_id']);

    expect(People::query()->find($duplicate->getKey()))->not->toBeNull()
        ->and(People::withTrashed()->count())->toBe(2)
        ->and(DB::table('noteables')->where('note_id', $note->getKey())->pluck('noteable_id')->all())->toBe([$duplicate->getKey()])
        ->and(DB::table('taskables')->where('task_id', $task->getKey())->pluck('taskable_id')->all())->toBe([$duplicate->getKey()])
        ->and($opportunity->refresh()->contact_id)->toBe($duplicate->getKey())
        ->and(Activity::query()->where('event', 'record_merged')->pluck('subject_id')->all())->toBe([$kept->getKey()]);
});

it('creates a new person with the current person\'s emails first and without repeats', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Whitfield'], ['emails' => ['dana@brightline.com', 'dana@gmail.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana W.'], ['emails' => ['dana@gmail.com', 'whitfield@outlook.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    $merged = mergedRecord();

    expect($merged->getKey())->not->toBeIn([$kept->getKey(), $duplicate->getKey()])
        ->and($merged->name)->toBe('Dana Whitfield')
        ->and(mergePersonValue($merged, 'emails'))->toBe(['dana@brightline.com', 'dana@gmail.com', 'whitfield@outlook.com'])
        ->and(People::withTrashed()->find($kept->getKey())->trashed())->toBeTrue()
        ->and(People::withTrashed()->find($duplicate->getKey())->trashed())->toBeTrue();
});

it('combines tags without repeating one that differs only in letter case or spacing', function (): void {
    CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'people',
        'code' => 'segments',
        'name' => 'Segments',
        'type' => 'tags-input',
    ]);
    $kept = mergePersonRecord($this->workspace, $this->user, [], ['segments' => ['VIP', 'Enterprise']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, [], ['segments' => [' vip', 'Partner']]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonValue(mergedRecord(), 'segments'))->toBe(['VIP', 'Enterprise', 'Partner']);
});

it('blocks a merge that would give a person more than five emails', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, [], ['emails' => ['a1@x.com', 'a2@x.com', 'a3@x.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, [], ['emails' => ['b1@x.com', 'b2@x.com', 'b3@x.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasActionErrors(['picked_id']);

    expect(People::query()->whereKey($duplicate->getKey())->exists())->toBeTrue()
        ->and(People::query()->count())->toBe(2)
        ->and(mergePersonValue($kept, 'emails'))->toHaveCount(3);
});

it('keeps the current person\'s phone number where both hold one', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, [], ['phone_number' => ['+14155550101']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, [], ['phone_number' => ['+14155550199'], 'job_title' => 'VP Sales']);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonValue(mergedRecord(), 'phone_number'))->toBe(['+14155550101'])
        ->and(mergePersonValue(mergedRecord(), 'job_title'))->toBe('VP Sales');
});

it('fills the company from the other person only when the current person has none', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $globex = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);
    $withoutCompany = mergePersonRecord($this->workspace, $this->user);
    $withCompany = mergePersonRecord($this->workspace, $this->user, ['company_id' => $acme->getKey()]);
    $duplicateOne = mergePersonRecord($this->workspace, $this->user, ['company_id' => $globex->getKey()]);
    $duplicateTwo = mergePersonRecord($this->workspace, $this->user, ['company_id' => $globex->getKey()]);

    mergePeopleWith($withoutCompany, $duplicateOne)->assertHasNoActionErrors();
    $fromEmpty = mergedRecord();
    mergePeopleWith($withCompany, $duplicateTwo)->assertHasNoActionErrors();

    expect($fromEmpty->company_id)->toBe($globex->getKey())
        ->and(mergedRecord()->company_id)->toBe($acme->getKey());
});

it('treats the current person\'s soft-deleted company as empty and takes the other person\'s live company', function (bool $duplicateHasCompany): void {
    $gone = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $live = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $kept = mergePersonRecord($this->workspace, $this->user, ['company_id' => $gone->getKey()]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['company_id' => $duplicateHasCompany ? $live->getKey() : null]);
    $gone->delete();

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergedRecord()->company_id)->toBe($duplicateHasCompany ? $live->getKey() : null);
})->with([true, false]);

it('finds a person by name or by any of their emails', function (string $term, array $found): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Kept']);
    mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Whitfield'], ['emails' => ['dana@brightline.com', 'whitfield@outlook.com']]);
    mergePersonRecord($this->workspace, $this->user, ['name' => 'Sam Rivera'], ['emails' => ['sam@northwind.com']]);

    expect(mergeSearchNames(ViewPeople::class, $kept, $term))->toBe($found);
})->with([
    'name' => ['Dana', ['Dana Whitfield']],
    'second email' => ['outlook', ['Dana Whitfield']],
    'email fragment' => ['sam@north', ['Sam Rivera']],
    'nothing' => ['zzz', []],
]);

it('shows no search detail for a person whose name is their email address', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Kept']);
    mergePersonRecord($this->workspace, $this->user, ['name' => '247496536@bcc.example'], ['emails' => ['247496536@bcc.example']]);

    $results = mergeSearch(ViewPeople::class, $kept, '247496536')->effects['returns'][0];

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('247496536@bcc.example')
        ->and($results[0]['detail'])->toBeNull();
});

it('leaves the current person, a trashed person and another workspace\'s person out of the search', function (): void {
    $foreignOwner = User::factory()->withWorkspace()->create();
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Current']);
    mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Live']);
    mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Trashed'])->delete();
    mergePersonRecord($foreignOwner->currentWorkspace, $foreignOwner, ['name' => 'Dana Foreign']);

    expect(mergeSearchNames(ViewPeople::class, $kept, 'Dana'))->toBe(['Dana Live']);
});

it('lets the other person win when the member swaps the two', function (): void {
    $current = mergePersonRecord($this->workspace, $this->user, ['name' => 'Sam'], ['emails' => ['sam@one.com']]);
    $other = mergePersonRecord($this->workspace, $this->user, ['name' => 'Samuel'], ['emails' => ['samuel@two.com']]);

    $page = mergePeopleWith($current, $other, keepPicked: true)->assertHasNoActionErrors();
    $merged = mergedRecord();

    $page->assertRedirect(PeopleResource::getUrl('view', ['record' => $merged]));

    expect($merged->name)->toBe('Samuel')
        ->and(People::withTrashed()->find($current->getKey())->trashed())->toBeTrue()
        ->and(People::withTrashed()->find($other->getKey())->trashed())->toBeTrue()
        ->and(mergePersonValue($merged, 'emails'))->toBe(['samuel@two.com', 'sam@one.com']);
});

it('moves both people\'s notes and tasks and keeps a shared one once', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user);
    $duplicate = mergePersonRecord($this->workspace, $this->user);
    [$onlyDuplicate, $shared, $onlyKept] = Note::factory()->count(3)->recycle([$this->user, $this->workspace])->create()->all();
    [$taskOnDuplicate, $sharedTask] = Task::factory()->count(2)->recycle([$this->user, $this->workspace])->create()->all();
    $duplicate->notes()->attach([$onlyDuplicate->getKey(), $shared->getKey()]);
    $kept->notes()->attach([$shared->getKey(), $onlyKept->getKey()]);
    $duplicate->tasks()->attach([$taskOnDuplicate->getKey(), $sharedTask->getKey()]);
    $kept->tasks()->attach($sharedTask->getKey());

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();
    $merged = mergedRecord();

    expect(mergeLinkedIds('noteables', 'note_id', $merged))->toBe(collect([$onlyDuplicate, $shared, $onlyKept])->map->getKey()->sort()->values()->all())
        ->and(mergeLinkedIds('taskables', 'task_id', $merged))->toBe(collect([$taskOnDuplicate, $sharedTask])->map->getKey()->sort()->values()->all())
        ->and(mergeLinkedIds('noteables', 'note_id', $kept))->toBe([])
        ->and(mergeLinkedIds('taskables', 'task_id', $kept))->toBe([])
        ->and(mergeLinkedIds('noteables', 'note_id', $duplicate))->toBe([])
        ->and(mergeLinkedIds('taskables', 'task_id', $duplicate))->toBe([]);
});

it('moves the opportunities either person is the point of contact for', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user);
    $duplicate = mergePersonRecord($this->workspace, $this->user);
    $bystander = mergePersonRecord($this->workspace, $this->user);
    $theirs = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $duplicate->getKey()]);
    $mine = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $kept->getKey()]);
    $other = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $bystander->getKey()]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($theirs->refresh()->contact_id)->toBe(mergedRecord()->getKey())
        ->and($mine->refresh()->contact_id)->toBe(mergedRecord()->getKey())
        ->and($other->refresh()->contact_id)->toBe($bystander->getKey());
});

it('keeps each person\'s own history and records the merge on all three people', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Whitfield']);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana W.']);
    $kept->update(['name' => 'Dana']);
    $kept->update(['name' => 'Dana Whitfield']);
    $duplicate->update(['name' => 'Dana W']);
    $keptRows = Activity::query()->where('subject_id', $kept->getKey())->where('event', 'updated')->pluck('id');
    $duplicateRows = Activity::query()->where('subject_id', $duplicate->getKey())->where('event', 'updated')->pluck('id');

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    $entries = Activity::query()->where('event', 'record_merged')->orderBy('id')->get();
    $entryOn = fn (string $subjectId): array => $entries->where('subject_id', $subjectId)->map(fn (Activity $entry): array => $entry->properties->all())->values()->all();

    expect($keptRows)->toHaveCount(2)
        ->and(Activity::query()->whereKey($keptRows)->pluck('subject_id')->unique()->all())->toBe([$kept->getKey()])
        ->and(Activity::query()->whereKey($duplicateRows)->pluck('subject_id')->unique()->all())->toBe([$duplicate->getKey()])
        ->and(array_column($entryOn(mergedRecord()->getKey()), 'merged_name'))->toBe(['Dana W'])
        ->and(array_column($entryOn($kept->getKey()), 'merged_into_name'))->toBe(['Dana Whitfield'])
        ->and(array_column($entryOn($duplicate->getKey()), 'merged_into_name'))->toBe(['Dana Whitfield'])
        ->and(Activity::query()->where('subject_id', $duplicate->getKey())->orderBy('id')->pluck('event')->all())->toBe(['created', 'updated', 'deleted', 'record_merged']);
});

it('gives the new person the current person\'s creator and source, and no onboarding turn', function (): void {
    $creator = User::factory()->create();
    $this->workspace->users()->attach($creator, ['role' => WorkspaceRole::Member->value]);
    $kept = mergePersonRecord($this->workspace, $this->user, ['creation_source' => CreationSource::MCP, 'creator_id' => $creator->getKey()]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['creation_source' => CreationSource::WEB]);
    Cache::flush();

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergedRecord()->creator_id)->toBe($creator->getKey())
        ->and(mergedRecord()->creation_source)->toBe(CreationSource::MCP)
        ->and(Cache::has("chat:assistant-arrival-scheduled:{$this->workspace->getKey()}"))->toBeFalse();
});

it('shows only the merge on the new person\'s timeline', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Whitfield', 'company_id' => null], ['emails' => ['dana@brightline.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana W.', 'company_id' => $company->getKey()], ['emails' => ['dana@gmail.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(Activity::query()->where('subject_id', mergedRecord()->getKey())->pluck('event')->all())->toBe(['record_merged'])
        ->and(mergedRecord()->company_id)->toBe($company->getKey());
});

it('repoints record-type attributes on other records to the new person', function (): void {
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'opportunity',
        'code' => 'champions',
        'name' => 'Champions',
        'type' => 'record',
        'lookup_type' => 'people',
        'settings' => new CustomFieldSettingsData(allow_multiple: true),
    ]);
    $kept = mergePersonRecord($this->workspace, $this->user);
    $duplicate = mergePersonRecord($this->workspace, $this->user);
    $other = mergePersonRecord($this->workspace, $this->user);
    $both = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $onlyDuplicate = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $unrelated = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $both->saveCustomFieldValue($field, [$duplicate->getKey(), $kept->getKey()]);
    $onlyDuplicate->saveCustomFieldValue($field, [$other->getKey(), $duplicate->getKey()]);
    $unrelated->saveCustomFieldValue($field, [$other->getKey()]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    $read = fn (Opportunity $opportunity): mixed => Opportunity::query()->with('customFieldValues.customField')->findOrFail($opportunity->getKey())->getCustomFieldValue($field);
    $merged = mergedRecord();

    expect($read($both))->toBe([$merged->getKey()])
        ->and($read($onlyDuplicate))->toBe([$other->getKey(), $merged->getKey()])
        ->and($read($unrelated))->toBe([$other->getKey()]);
});

it('points the new person\'s record attribute at itself where an original named the other', function (): void {
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'people',
        'code' => 'colleagues',
        'name' => 'Colleagues',
        'type' => 'record',
        'lookup_type' => 'people',
        'settings' => new CustomFieldSettingsData(allow_multiple: true),
    ]);
    $partner = mergePersonRecord($this->workspace, $this->user);
    $supplier = mergePersonRecord($this->workspace, $this->user);
    $kept = mergePersonRecord($this->workspace, $this->user);
    $duplicate = mergePersonRecord($this->workspace, $this->user);
    $kept->saveCustomFieldValue($field, [$duplicate->getKey(), $partner->getKey()]);
    $duplicate->saveCustomFieldValue($field, [$kept->getKey(), $supplier->getKey()]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    $merged = mergedRecord();

    expect(mergePersonValue($merged, 'colleagues'))->toBe([$merged->getKey(), $partner->getKey(), $supplier->getKey()]);
});

it('copies a rich-text value with its image from the duplicate', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'people',
        'code' => 'brief',
        'name' => 'Brief',
        'type' => 'rich-editor',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $kept = mergePersonRecord($this->workspace, $this->user);
    $duplicate = mergePersonRecord($this->workspace, $this->user);
    $image = mergeMedia($duplicate, MediaCollection::Attachments->value);
    $duplicate->saveCustomFieldValue($field, "<p>Notes</p><p><img data-id=\"{$image->uuid}\" src=\"stale\"></p>");

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    $image = Media::query()->where('uuid', $image->uuid)->firstOrFail();

    $merged = mergedRecord();

    expect(mergePersonValue($merged, 'brief'))->toContain($image->uuid)
        ->and($image->model_id)->toBe($merged->getKey())
        ->and($image->collection_name)->toBe(MediaCollection::Attachments->value);
});

it('shows who merged the two people on the new person\'s timeline', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Whitfield']);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana W.']);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    livewire(ActivityLogLivewire::class, ['subjectClass' => People::class, 'subjectKey' => mergedRecord()->getKey()])
        ->assertSee(e($this->user->name.' merged Dana W. into this record'), escape: false)
        ->assertSeeHtml('data-event="record_merged"');
});

it('makes the new person real data when the other person was real', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['creation_source' => CreationSource::SAMPLE]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['creation_source' => CreationSource::WEB]);

    mergePeopleWith($kept, $duplicate);
    $merged = mergedRecord();

    expect($merged->creation_source)->toBe(CreationSource::WEB);

    resolve(RemoveSampleData::class)->execute($this->user, $this->workspace);

    expect(People::query()->whereKey($merged->getKey())->exists())->toBeTrue();
});

it('hides merge from a member who cannot create, update and delete people', function (): void {
    $person = mergePersonRecord($this->workspace, $this->user);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(ViewPeople::class, ['record' => $person->getKey()])->assertActionHidden(mergeRecordAction());
});

it('offers merge to a member who can create, update and delete people', function (): void {
    $person = mergePersonRecord($this->workspace, $this->user);

    livewire(ViewPeople::class, ['record' => $person->getKey()])->assertActionVisible(mergeRecordAction());
});

it('does not offer merge on an opportunity', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])->assertActionHidden(mergeRecordAction());
});

it('refuses the current person, a trashed person and another workspace\'s person', function (string $case): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Kept']);
    $foreignOwner = User::factory()->withWorkspace()->create();
    $forged = match ($case) {
        'itself' => $kept,
        'trashed' => tap(mergePersonRecord($this->workspace, $this->user), fn (People $person) => $person->delete()),
        'other workspace' => People::factory()->recycle([$foreignOwner, $foreignOwner->currentWorkspace])->create(),
    };

    mergePeopleWith($kept, $forged)->assertHasActionErrors(['picked_id']);

    expect(People::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(People::withTrashed()->withoutGlobalScopes()->find($forged->getKey())->trashed())->toBe($case === 'trashed');
})->with(['itself', 'trashed', 'other workspace']);

it('shows the unavailable error when the duplicate is deleted after it was picked in the dialog', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Kept']);
    $duplicate = mergePersonRecord($this->workspace, $this->user, [], ['job_title' => 'VP Sales']);

    $page = livewire(ViewPeople::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $duplicate->getKey(), 'keep_picked' => false]);
    $duplicate->delete();
    $page->callMountedAction()->assertHasActionErrors(['picked_id' => __('filament/record-page.merge.unavailable')]);

    expect(mergePersonValue($kept, 'job_title'))->toBeNull()
        ->and($kept->refresh()->name)->toBe('Kept');
});

it('shows the unavailable error when the duplicate disappears while the merge starts', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Kept']);
    $duplicate = mergePersonRecord($this->workspace, $this->user);

    $page = livewire(ViewPeople::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $duplicate->getKey(), 'keep_picked' => false]);
    Event::listen(TransactionBeginning::class, fn (): int => DB::table('people')->where('id', $duplicate->getKey())->update(['deleted_at' => now()]));
    $page->callMountedAction()->assertHasActionErrors(['picked_id' => __('filament/record-page.merge.unavailable')]);

    expect(People::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(People::withTrashed()->count())->toBe(2)
        ->and(Activity::query()->where('subject_id', $kept->getKey())->where('event', 'record_merged')->exists())->toBeFalse();
});

it('shows the unavailable error when the kept person disappears while the merge starts', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Kept']);
    $duplicate = mergePersonRecord($this->workspace, $this->user);
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $duplicate->notes()->attach($note->getKey());

    $page = livewire(ViewPeople::class, ['record' => $kept->getKey()])
        ->mountAction(mergeRecordAction())
        ->setActionData(['picked_id' => $duplicate->getKey(), 'keep_picked' => false]);
    Event::listen(TransactionBeginning::class, fn (): int => DB::table('people')->where('id', $kept->getKey())->update(['deleted_at' => now()]));
    $page->callMountedAction()->assertHasActionErrors(['picked_id' => __('filament/record-page.merge.unavailable')]);

    expect(People::query()->find($duplicate->getKey()))->not->toBeNull()
        ->and(People::withTrashed()->count())->toBe(2)
        ->and(mergeLinkedIds('noteables', 'note_id', $duplicate))->toBe([$note->getKey()])
        ->and(Activity::query()->where('event', 'record_merged')->exists())->toBeFalse();
});

it('counts emails case-insensitively before applying the limit', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, [], ['emails' => ['a1@x.com', 'a2@x.com', 'a3@x.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, [], ['emails' => ['A1@X.com', ' a2@x.com', 'a3@x.com', 'b1@x.com', 'b2@x.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonValue(mergedRecord(), 'emails'))->toBe(['a1@x.com', 'a2@x.com', 'a3@x.com', 'b1@x.com', 'b2@x.com']);
});

it('locks both people with one select in id order, whichever one is kept', function (): void {
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'for update') && str_contains($query->sql, ' in (')) {
            $locks[] = $query;
        }
    });

    foreach (['current' => false, 'picked' => true] as $kept => $keepPicked) {
        $locks = [];
        $current = mergePersonRecord($this->workspace, $this->user);
        $other = mergePersonRecord($this->workspace, $this->user);

        mergePeopleWith($current, $other, $keepPicked)->assertHasNoActionErrors();

        expect($locks)->toHaveCount(1, $kept)
            ->and($locks[0]->sql)->toStartWith('select * from "people"')
            ->and($locks[0]->sql)->toEndWith('order by "id" asc for update')
            ->and($locks[0]->bindings)->toContain($current->getKey(), $other->getKey());
    }
});
