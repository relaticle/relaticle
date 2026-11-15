<?php

declare(strict_types=1);

use App\Actions\Merge\MergePeople;
use App\Actions\Merge\MergeRecords;
use App\Actions\Onboarding\RemoveSampleData;
use App\ActivityLog\AppEventPalette;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\MediaCollection;
use App\Enums\RecordIdentity;
use App\Enums\WorkspaceRole;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Helpers\WorkspaceCustomField;

mutates(MergePeople::class, MergeRecords::class, MergeRecordAction::class, MergeRecordPicker::class, RecordIdentity::class, CrmEntity::class, MergedActivityRenderer::class, AppEventPalette::class);

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

it('combines emails with the kept person first and without repeats', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Whitfield'], ['emails' => ['dana@brightline.com', 'dana@gmail.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana W.'], ['emails' => ['dana@gmail.com', 'whitfield@outlook.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($kept->refresh()->name)->toBe('Dana Whitfield')
        ->and(mergePersonValue($kept, 'emails'))->toBe(['dana@brightline.com', 'dana@gmail.com', 'whitfield@outlook.com'])
        ->and(People::withTrashed()->find($duplicate->getKey())->trashed())->toBeTrue()
        ->and(People::query()->find($kept->getKey()))->not->toBeNull();
});

it('blocks a merge that would give a person more than five emails', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, [], ['emails' => ['a1@x.com', 'a2@x.com', 'a3@x.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, [], ['emails' => ['b1@x.com', 'b2@x.com', 'b3@x.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasActionErrors(['picked_id']);

    expect(People::query()->whereKey($duplicate->getKey())->exists())->toBeTrue()
        ->and(mergePersonValue($kept, 'emails'))->toHaveCount(3);
});

it('keeps the phone number of the kept person where both hold one', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, [], ['phone_number' => ['+14155550101']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, [], ['phone_number' => ['+14155550199'], 'job_title' => 'VP Sales']);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonValue($kept, 'phone_number'))->toBe(['+14155550101'])
        ->and(mergePersonValue($kept, 'job_title'))->toBe('VP Sales');
});

it('fills the company from the duplicate only when the kept person has none', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $globex = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);
    $withoutCompany = mergePersonRecord($this->workspace, $this->user);
    $withCompany = mergePersonRecord($this->workspace, $this->user, ['company_id' => $acme->getKey()]);
    $duplicateOne = mergePersonRecord($this->workspace, $this->user, ['company_id' => $globex->getKey()]);
    $duplicateTwo = mergePersonRecord($this->workspace, $this->user, ['company_id' => $globex->getKey()]);

    mergePeopleWith($withoutCompany, $duplicateOne)->assertHasNoActionErrors();
    mergePeopleWith($withCompany, $duplicateTwo)->assertHasNoActionErrors();

    expect($withoutCompany->refresh()->company_id)->toBe($globex->getKey())
        ->and($withCompany->refresh()->company_id)->toBe($acme->getKey());
});

it('treats the kept person\'s soft-deleted company as empty and takes the duplicate\'s live company', function (bool $duplicateHasCompany): void {
    $gone = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $live = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $kept = mergePersonRecord($this->workspace, $this->user, ['company_id' => $gone->getKey()]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['company_id' => $duplicateHasCompany ? $live->getKey() : null]);
    $gone->delete();

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($kept->refresh()->company_id)->toBe($duplicateHasCompany ? $live->getKey() : null)
        ->and(People::withTrashed()->find($duplicate->getKey())->trashed())->toBeTrue();
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

it('leaves the current person, a trashed person and another workspace\'s person out of the search', function (): void {
    $foreignOwner = User::factory()->withWorkspace()->create();
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Current']);
    mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Live']);
    mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Trashed'])->delete();
    mergePersonRecord($foreignOwner->currentWorkspace, $foreignOwner, ['name' => 'Dana Foreign']);

    expect(mergeSearchNames(ViewPeople::class, $kept, 'Dana'))->toBe(['Dana Live']);
});

it('keeps the other person when the member swaps the two', function (): void {
    $current = mergePersonRecord($this->workspace, $this->user, ['name' => 'Sam'], ['emails' => ['sam@one.com']]);
    $other = mergePersonRecord($this->workspace, $this->user, ['name' => 'Samuel'], ['emails' => ['samuel@two.com']]);

    mergePeopleWith($current, $other, keepPicked: true)
        ->assertHasNoActionErrors()
        ->assertRedirect(PeopleResource::getUrl('view', ['record' => $other]));

    expect(People::query()->find($other->getKey())->name)->toBe('Samuel')
        ->and(People::withTrashed()->find($current->getKey())->trashed())->toBeTrue()
        ->and(mergePersonValue($other, 'emails'))->toBe(['samuel@two.com', 'sam@one.com']);
});

it('moves the duplicate\'s notes and tasks and keeps a shared one once', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user);
    $duplicate = mergePersonRecord($this->workspace, $this->user);
    [$onlyDuplicate, $shared, $onlyKept] = Note::factory()->count(3)->recycle([$this->user, $this->workspace])->create()->all();
    [$taskOnDuplicate, $sharedTask] = Task::factory()->count(2)->recycle([$this->user, $this->workspace])->create()->all();
    $duplicate->notes()->attach([$onlyDuplicate->getKey(), $shared->getKey()]);
    $kept->notes()->attach([$shared->getKey(), $onlyKept->getKey()]);
    $duplicate->tasks()->attach([$taskOnDuplicate->getKey(), $sharedTask->getKey()]);
    $kept->tasks()->attach($sharedTask->getKey());

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergeLinkedIds('noteables', 'note_id', $kept))->toBe(collect([$onlyDuplicate, $shared, $onlyKept])->map->getKey()->sort()->values()->all())
        ->and(mergeLinkedIds('taskables', 'task_id', $kept))->toBe(collect([$taskOnDuplicate, $sharedTask])->map->getKey()->sort()->values()->all())
        ->and(mergeLinkedIds('noteables', 'note_id', $duplicate))->toBe([])
        ->and(mergeLinkedIds('taskables', 'task_id', $duplicate))->toBe([]);
});

it('moves the opportunities the duplicate is the point of contact for', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user);
    $duplicate = mergePersonRecord($this->workspace, $this->user);
    $bystander = mergePersonRecord($this->workspace, $this->user);
    $theirs = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $duplicate->getKey()]);
    $mine = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $kept->getKey()]);
    $other = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $bystander->getKey()]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($theirs->refresh()->contact_id)->toBe($kept->getKey())
        ->and($mine->refresh()->contact_id)->toBe($kept->getKey())
        ->and($other->refresh()->contact_id)->toBe($bystander->getKey());
});

it('moves the duplicate\'s history to the kept person and adds a merged entry', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Whitfield']);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana W.']);
    $duplicate->update(['name' => 'Dana W']);
    $duplicateRows = Activity::query()->where('subject_type', 'people')->where('subject_id', $duplicate->getKey())->pluck('id');

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    $merged = Activity::query()->where('subject_id', $kept->getKey())->where('event', 'record_merged')->sole();

    expect($duplicateRows)->not->toBeEmpty()
        ->and(Activity::query()->whereKey($duplicateRows)->pluck('subject_id')->unique()->all())->toBe([$kept->getKey()])
        ->and($merged->properties['duplicate_name'])->toBe('Dana W')
        ->and($merged->causer_id)->toBe($this->user->getKey())
        ->and($merged->workspace_id)->toBe($this->workspace->getKey())
        ->and(Activity::query()->where('subject_id', $duplicate->getKey())->pluck('event')->all())->toBe(['deleted']);
});

it('repoints record-type attributes on other records to the kept person', function (): void {
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

    expect($read($both))->toBe([$kept->getKey()])
        ->and($read($onlyDuplicate))->toBe([$other->getKey(), $kept->getKey()])
        ->and($read($unrelated))->toBe([$other->getKey()]);
});

it('points the kept person\'s own record attribute at itself instead of the duplicate', function (): void {
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
    $duplicate->saveCustomFieldValue($field, [$supplier->getKey()]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonValue($kept, 'colleagues'))->toBe([$kept->getKey(), $partner->getKey(), $supplier->getKey()]);
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

    expect(mergePersonValue($kept, 'brief'))->toContain($image->uuid)
        ->and($image->model_id)->toBe($kept->getKey())
        ->and($image->collection_name)->toBe(MediaCollection::Attachments->value);
});

it('shows who merged the duplicate on the kept person\'s timeline', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Whitfield']);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana W.']);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    livewire(ActivityLogLivewire::class, ['subjectClass' => People::class, 'subjectKey' => $kept->getKey()])
        ->assertSee(e($this->user->name.' merged Dana W. into this record'), escape: false)
        ->assertSeeHtml('data-event="record_merged"');
});

it('makes the kept person real data when the duplicate was real', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, ['creation_source' => CreationSource::SAMPLE]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['creation_source' => CreationSource::WEB]);

    mergePeopleWith($kept, $duplicate);

    expect($kept->refresh()->creation_source)->toBe(CreationSource::WEB);

    resolve(RemoveSampleData::class)->execute($this->user, $this->workspace);

    expect(People::query()->whereKey($kept->getKey())->exists())->toBeTrue();
});

it('hides merge from a member who cannot update and delete the person', function (): void {
    $person = mergePersonRecord($this->workspace, $this->user);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(ViewPeople::class, ['record' => $person->getKey()])->assertActionHidden(mergeRecordAction());
});

it('offers merge to a member who can update and delete the person', function (): void {
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
        ->and(mergeLinkedIds('noteables', 'note_id', $duplicate))->toBe([$note->getKey()])
        ->and(Activity::query()->where('event', 'record_merged')->exists())->toBeFalse();
});

it('counts emails case-insensitively before applying the limit', function (): void {
    $kept = mergePersonRecord($this->workspace, $this->user, [], ['emails' => ['a1@x.com', 'a2@x.com', 'a3@x.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, [], ['emails' => ['A1@X.com', ' a2@x.com', 'a3@x.com', 'b1@x.com', 'b2@x.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonValue($kept, 'emails'))->toBe(['a1@x.com', 'a2@x.com', 'a3@x.com', 'b1@x.com', 'b2@x.com']);
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
