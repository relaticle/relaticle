<?php

declare(strict_types=1);

use App\Actions\Merge\MergePeople;
use App\Actions\Merge\MergeRecords;
use App\Actions\Onboarding\RemoveSampleData;
use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
use App\Filament\Actions\MergeRecordAction;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\WorkspaceCustomField;

mutates(MergePeople::class, MergeRecords::class, MergeRecordAction::class);

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

function mergePersonAction(): TestAction
{
    return TestAction::make('mergeRecord')->schemaComponent('recordActions', schema: 'infolist');
}

function mergePerson(Workspace $workspace, User $owner, array $attributes = [], array $customFields = []): People
{
    $person = People::factory()->recycle([$owner, $workspace])->create($attributes);

    foreach ($customFields as $code => $value) {
        $person->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), 'people', $code), $value);
    }

    return $person;
}

function mergePersonValue(People $person, string $code): mixed
{
    $person = People::withTrashed()->with('customFieldValues.customField')->findOrFail($person->getKey());

    return $person->getCustomFieldValue(WorkspaceCustomField::byCode($person->workspace_id, 'people', $code));
}

function mergePeopleWith(People $kept, People $duplicate, string $keep = 'current'): mixed
{
    return livewire(ViewPeople::class, ['record' => $kept->getKey()])
        ->callAction(mergePersonAction(), data: ['duplicate_id' => $duplicate->getKey(), 'keep' => $keep]);
}

function mergePersonLinkedIds(string $table, string $itemColumn, string $morphColumn, People $person): array
{
    return DB::table($table)->where($morphColumn.'_type', 'people')->where($morphColumn.'_id', $person->getKey())->pluck($itemColumn)->sort()->values()->all();
}

it('combines emails with the kept person first and without repeats', function (): void {
    $kept = mergePerson($this->workspace, $this->user, ['name' => 'Dana Whitfield'], ['emails' => ['dana@brightline.com', 'dana@gmail.com']]);
    $duplicate = mergePerson($this->workspace, $this->user, ['name' => 'Dana W.'], ['emails' => ['dana@gmail.com', 'whitfield@outlook.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($kept->refresh()->name)->toBe('Dana Whitfield')
        ->and(mergePersonValue($kept, 'emails'))->toBe(['dana@brightline.com', 'dana@gmail.com', 'whitfield@outlook.com'])
        ->and(People::withTrashed()->find($duplicate->getKey())->trashed())->toBeTrue()
        ->and(People::query()->find($kept->getKey()))->not->toBeNull();
});

it('blocks a merge that would give a person more than five emails', function (): void {
    $kept = mergePerson($this->workspace, $this->user, [], ['emails' => ['a1@x.com', 'a2@x.com', 'a3@x.com']]);
    $duplicate = mergePerson($this->workspace, $this->user, [], ['emails' => ['b1@x.com', 'b2@x.com', 'b3@x.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasActionErrors(['duplicate_id']);

    expect(People::query()->whereKey($duplicate->getKey())->exists())->toBeTrue()
        ->and(mergePersonValue($kept, 'emails'))->toHaveCount(3);
});

it('keeps the phone number of the kept person where both hold one', function (): void {
    $kept = mergePerson($this->workspace, $this->user, [], ['phone_number' => ['+14155550101']]);
    $duplicate = mergePerson($this->workspace, $this->user, [], ['phone_number' => ['+14155550199'], 'job_title' => 'VP Sales']);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonValue($kept, 'phone_number'))->toBe(['+14155550101'])
        ->and(mergePersonValue($kept, 'job_title'))->toBe('VP Sales');
});

it('fills the company from the duplicate only when the kept person has none', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $globex = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);
    $withoutCompany = mergePerson($this->workspace, $this->user);
    $withCompany = mergePerson($this->workspace, $this->user, ['company_id' => $acme->getKey()]);
    $duplicateOne = mergePerson($this->workspace, $this->user, ['company_id' => $globex->getKey()]);
    $duplicateTwo = mergePerson($this->workspace, $this->user, ['company_id' => $globex->getKey()]);

    mergePeopleWith($withoutCompany, $duplicateOne)->assertHasNoActionErrors();
    mergePeopleWith($withCompany, $duplicateTwo)->assertHasNoActionErrors();

    expect($withoutCompany->refresh()->company_id)->toBe($globex->getKey())
        ->and($withCompany->refresh()->company_id)->toBe($acme->getKey());
});

it('treats the kept person\'s soft-deleted company as empty and takes the duplicate\'s live company', function (bool $duplicateHasCompany): void {
    $gone = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $live = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $kept = mergePerson($this->workspace, $this->user, ['company_id' => $gone->getKey()]);
    $duplicate = mergePerson($this->workspace, $this->user, ['company_id' => $duplicateHasCompany ? $live->getKey() : null]);
    $gone->delete();

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($kept->refresh()->company_id)->toBe($duplicateHasCompany ? $live->getKey() : null)
        ->and(People::withTrashed()->find($duplicate->getKey())->trashed())->toBeTrue();
})->with([true, false]);

function mergePersonDialog(People $kept, string $search): string
{
    $page = livewire(ViewPeople::class, ['record' => $kept->getKey()])
        ->mountAction(mergePersonAction())
        ->setActionData(['search' => $search]);

    return implode('', Arr::where($page->effects['partials'], fn (string $html, string $key): bool => str_starts_with($key, 'action-modals')));
}

it('finds a person by name or by any of their emails', function (string $term, array $found): void {
    $kept = mergePerson($this->workspace, $this->user, ['name' => 'Kept']);
    mergePerson($this->workspace, $this->user, ['name' => 'Dana Whitfield'], ['emails' => ['dana@brightline.com', 'whitfield@outlook.com']]);
    mergePerson($this->workspace, $this->user, ['name' => 'Sam Rivera'], ['emails' => ['sam@northwind.com']]);

    $dialog = mergePersonDialog($kept, $term);

    foreach (['Dana Whitfield', 'Sam Rivera'] as $name) {
        in_array($name, $found, true) ? expect($dialog)->toContain($name) : expect($dialog)->not->toContain($name);
    }
})->with([
    'name' => ['Dana', ['Dana Whitfield']],
    'second email' => ['outlook', ['Dana Whitfield']],
    'email fragment' => ['sam@north', ['Sam Rivera']],
    'nothing' => ['zzz', []],
]);

it('keeps the other person when the member swaps the two', function (): void {
    $current = mergePerson($this->workspace, $this->user, ['name' => 'Sam'], ['emails' => ['sam@one.com']]);
    $other = mergePerson($this->workspace, $this->user, ['name' => 'Samuel'], ['emails' => ['samuel@two.com']]);

    mergePeopleWith($current, $other, 'other')
        ->assertHasNoActionErrors()
        ->assertRedirect(PeopleResource::getUrl('view', ['record' => $other]));

    expect(People::query()->find($other->getKey())->name)->toBe('Samuel')
        ->and(People::withTrashed()->find($current->getKey())->trashed())->toBeTrue()
        ->and(mergePersonValue($other, 'emails'))->toBe(['samuel@two.com', 'sam@one.com']);
});

it('moves the duplicate\'s notes and tasks and keeps a shared one once', function (): void {
    $kept = mergePerson($this->workspace, $this->user);
    $duplicate = mergePerson($this->workspace, $this->user);
    [$onlyDuplicate, $shared, $onlyKept] = Note::factory()->count(3)->recycle([$this->user, $this->workspace])->create()->all();
    [$taskOnDuplicate, $sharedTask] = Task::factory()->count(2)->recycle([$this->user, $this->workspace])->create()->all();
    $duplicate->notes()->attach([$onlyDuplicate->getKey(), $shared->getKey()]);
    $kept->notes()->attach([$shared->getKey(), $onlyKept->getKey()]);
    $duplicate->tasks()->attach([$taskOnDuplicate->getKey(), $sharedTask->getKey()]);
    $kept->tasks()->attach($sharedTask->getKey());

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonLinkedIds('noteables', 'note_id', 'noteable', $kept))->toBe(collect([$onlyDuplicate, $shared, $onlyKept])->map->getKey()->sort()->values()->all())
        ->and(mergePersonLinkedIds('taskables', 'task_id', 'taskable', $kept))->toBe(collect([$taskOnDuplicate, $sharedTask])->map->getKey()->sort()->values()->all())
        ->and(mergePersonLinkedIds('noteables', 'note_id', 'noteable', $duplicate))->toBe([])
        ->and(mergePersonLinkedIds('taskables', 'task_id', 'taskable', $duplicate))->toBe([]);
});

it('moves the opportunities the duplicate is the point of contact for', function (): void {
    $kept = mergePerson($this->workspace, $this->user);
    $duplicate = mergePerson($this->workspace, $this->user);
    $bystander = mergePerson($this->workspace, $this->user);
    $theirs = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $duplicate->getKey()]);
    $mine = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $kept->getKey()]);
    $other = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $bystander->getKey()]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect($theirs->refresh()->contact_id)->toBe($kept->getKey())
        ->and($mine->refresh()->contact_id)->toBe($kept->getKey())
        ->and($other->refresh()->contact_id)->toBe($bystander->getKey());
});

it('moves the duplicate\'s history to the kept person and adds a merged entry', function (): void {
    $kept = mergePerson($this->workspace, $this->user, ['name' => 'Dana Whitfield']);
    $duplicate = mergePerson($this->workspace, $this->user, ['name' => 'Dana W.']);
    $duplicate->update(['name' => 'Dana W']);
    $duplicateRows = Activity::query()->where('subject_type', 'people')->where('subject_id', $duplicate->getKey())->pluck('id');

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    $merged = Activity::query()->where('subject_id', $kept->getKey())->where('event', 'record_merged')->sole();

    expect($duplicateRows)->not->toBeEmpty()
        ->and(Activity::query()->whereKey($duplicateRows)->pluck('subject_id')->unique()->all())->toBe([$kept->getKey()])
        ->and($merged->properties['duplicate_name'])->toBe('Dana W')
        ->and($merged->causer_id)->toBe($this->user->getKey())
        ->and($merged->workspace_id)->toBe($this->workspace->getKey());
});

it('makes the kept person real data when the duplicate was real', function (): void {
    $kept = mergePerson($this->workspace, $this->user, ['creation_source' => CreationSource::SAMPLE]);
    $duplicate = mergePerson($this->workspace, $this->user, ['creation_source' => CreationSource::WEB]);

    mergePeopleWith($kept, $duplicate);

    expect($kept->refresh()->creation_source)->toBe(CreationSource::WEB);

    resolve(RemoveSampleData::class)->execute($this->user, $this->workspace);

    expect(People::query()->whereKey($kept->getKey())->exists())->toBeTrue();
});

it('hides merge from a member who cannot update and delete the person', function (): void {
    $person = mergePerson($this->workspace, $this->user);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(ViewPeople::class, ['record' => $person->getKey()])->assertActionHidden(mergePersonAction());
});

it('offers merge to a member who can update and delete the person', function (): void {
    $person = mergePerson($this->workspace, $this->user);

    livewire(ViewPeople::class, ['record' => $person->getKey()])->assertActionVisible(mergePersonAction());
});

it('does not offer merge on an opportunity', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])->assertActionHidden(mergePersonAction());
});

it('refuses the current person, a trashed person and another workspace\'s person', function (string $case): void {
    $kept = mergePerson($this->workspace, $this->user, ['name' => 'Kept']);
    $foreignOwner = User::factory()->withWorkspace()->create();
    $forged = match ($case) {
        'itself' => $kept,
        'trashed' => tap(mergePerson($this->workspace, $this->user), fn (People $person) => $person->delete()),
        'other workspace' => People::factory()->recycle([$foreignOwner, $foreignOwner->currentWorkspace])->create(),
    };

    mergePeopleWith($kept, $forged)->assertHasActionErrors(['duplicate_id']);

    expect(People::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(People::withTrashed()->withoutGlobalScopes()->find($forged->getKey())->trashed())->toBe($case === 'trashed');
})->with(['itself', 'trashed', 'other workspace']);

it('shows the unavailable error when the duplicate is deleted after it was picked in the dialog', function (): void {
    $kept = mergePerson($this->workspace, $this->user, ['name' => 'Kept']);
    $duplicate = mergePerson($this->workspace, $this->user, [], ['job_title' => 'VP Sales']);

    $page = livewire(ViewPeople::class, ['record' => $kept->getKey()])
        ->mountAction(mergePersonAction())
        ->setActionData(['duplicate_id' => $duplicate->getKey(), 'keep' => 'current']);
    $duplicate->delete();
    $page->callMountedAction()->assertHasActionErrors(['duplicate_id' => __('filament/record-page.merge.unavailable')]);

    expect(mergePersonValue($kept, 'job_title'))->toBeNull()
        ->and($kept->refresh()->name)->toBe('Kept');
});

it('shows the unavailable error when the duplicate disappears while the merge starts', function (): void {
    $kept = mergePerson($this->workspace, $this->user, ['name' => 'Kept']);
    $duplicate = mergePerson($this->workspace, $this->user);

    $page = livewire(ViewPeople::class, ['record' => $kept->getKey()])
        ->mountAction(mergePersonAction())
        ->setActionData(['duplicate_id' => $duplicate->getKey(), 'keep' => 'current']);
    Event::listen(TransactionBeginning::class, fn (): int => DB::table('people')->where('id', $duplicate->getKey())->update(['deleted_at' => now()]));
    $page->callMountedAction()->assertHasActionErrors(['duplicate_id' => __('filament/record-page.merge.unavailable')]);

    expect(People::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_id', $kept->getKey())->where('event', 'record_merged')->exists())->toBeFalse();
});

it('counts emails case-insensitively before applying the limit', function (): void {
    $kept = mergePerson($this->workspace, $this->user, [], ['emails' => ['a1@x.com', 'a2@x.com', 'a3@x.com']]);
    $duplicate = mergePerson($this->workspace, $this->user, [], ['emails' => ['A1@X.com', ' a2@x.com', 'a3@x.com', 'b1@x.com', 'b2@x.com']]);

    mergePeopleWith($kept, $duplicate)->assertHasNoActionErrors();

    expect(mergePersonValue($kept, 'emails'))->toBe(['a1@x.com', 'a2@x.com', 'a3@x.com', 'b1@x.com', 'b2@x.com']);
});
