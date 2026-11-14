<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\FilterRuleCompiler;
use App\Filament\Tables\FilterBar\FilterRules;
use App\Filament\Tables\FilterBar\SortList;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Filters\LogicFilter;
use App\Queries\Filters\TreeFilter;
use App\Queries\FilterTree;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Laravel\Sanctum\Sanctum;

mutates(
    ConditionsFilter::class,
    FilterCondition::class,
    FilterFieldCatalog::class,
    FilterRuleCompiler::class,
    FilterRules::class,
    HasCustomFieldColumns::class,
    HasFilterBar::class,
    LogicFilter::class,
    PersistsTableState::class,
    SortList::class,
    TreeFilter::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();

    openOpportunitiesOnTheList($this->user, $this->workspace);
});

it('shows every record for a date rule that compiles to nothing', function (mixed $condition, mixed $value): void {
    $this->travelTo('2026-03-18 12:00:00');
    $records = filterBarCreatedAtRecords($this->user);

    livewire(ListCompanies::class)
        ->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => $condition, 'value' => $value]])
        ->assertCountTableRecords(count($records));
})->with([
    'a time of day' => ['is', '2026-03-18T10:00:00Z'],
    'text' => ['before', 'yesterday'],
    'a day that does not exist' => ['after', '2026-02-30'],
    'no value' => ['before', null],
    'a list' => ['is', ['2026-03-18']],
    'no amount' => ['in_the_past', ['unit' => 'days']],
    'a zero amount' => ['in_the_past', ['amount' => 0, 'unit' => 'days']],
    'an amount past the maximum' => ['in_the_next', ['amount' => 3651, 'unit' => 'days']],
    'a fractional amount' => ['in_the_next', ['amount' => 1.5, 'unit' => 'days']],
    'an unknown unit' => ['in_the_past', ['amount' => 3, 'unit' => 'fortnights']],
    'no unit' => ['this', []],
    'a scalar period' => ['in_the_past', '30'],
]);

it('keeps a month end from overflowing when it counts months back', function (): void {
    $this->travelTo('2026-03-31 12:00:00');
    filterBarRecord($this->user, Company::class, 'Feb28', ['created_at' => '2026-02-28 10:00:00']);
    filterBarRecord($this->user, Company::class, 'Feb27', ['created_at' => '2026-02-27 10:00:00']);

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => 'in_the_past', 'value' => ['amount' => 1, 'unit' => 'months']]]);

    expect(filterBarShownNames($component))->toBe(['Feb28']);
});

it('filters a date custom field by a day and by a period as the api does', function (): void {
    $this->travelTo('2026-03-18 12:00:00');
    $soon = filterBarRecord($this->user, Opportunity::class, 'Soon', values: ['close_date' => '2026-03-25']);
    $later = filterBarRecord($this->user, Opportunity::class, 'Later', values: ['close_date' => '2026-06-25']);
    $unset = filterBarRecord($this->user, Opportunity::class, 'Unset');
    $compile = fn (array $rules): array => (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::Opportunity, $this->user), $this->user, 'UTC');
    $next = [['field' => 'custom_fields.close_date', 'condition' => 'in_the_next', 'value' => ['amount' => 14, 'unit' => 'days']]];
    $exact = [['field' => 'custom_fields.close_date', 'condition' => 'is', 'value' => '2026-06-25']];

    $nextShown = livewire(ListOpportunities::class)->set('tableFilters.conditions.rules', $next);
    $exactShown = livewire(ListOpportunities::class)->set('tableFilters.conditions.rules', $exact);

    Sanctum::actingAs($this->user);

    expect(filterBarShownNames($nextShown))->toBe(['Soon'])
        ->and(filterBarApiIds($this, 'opportunities', $compile($next)))->toBe([$soon->getKey()])
        ->and(filterBarShownNames($exactShown))->toBe(['Later'])
        ->and(filterBarApiIds($this, 'opportunities', $compile($exact)))->toBe([$later->getKey()])
        ->and($unset->getKey())->not->toBeIn([$soon->getKey(), $later->getKey()]);
});

function filterBarLinkedPeople(User $user): array
{
    $acme = filterBarRecord($user, Company::class, 'Acme');
    $globex = filterBarRecord($user, Company::class, 'Globex');

    return [
        $acme,
        $globex,
        filterBarRecord($user, People::class, 'At Acme', ['company_id' => $acme->getKey()]),
        filterBarRecord($user, People::class, 'At Globex', ['company_id' => $globex->getKey()]),
        filterBarRecord($user, People::class, 'Freelance'),
    ];
}

it('filters a relation by record ids as the api does', function (string $condition, array $expected): void {
    [$acme] = $records = filterBarLinkedPeople($this->user);
    $rules = [['field' => 'company', 'condition' => $condition, 'value' => [$acme->getKey()]]];
    $tree = (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::People, $this->user), $this->user, 'UTC');
    $expectedIds = collect($records)->filter(fn (Model $record): bool => in_array($record->name, $expected, true))->map->getKey()->sort()->values()->all();

    $shown = livewire(ListPeople::class)->set('tableFilters.conditions.rules', $rules);

    Sanctum::actingAs($this->user);

    expect(filterBarShownNames($shown))->toBe(collect($expected)->sort()->values()->all())
        ->and(filterBarApiIds($this, 'people', $tree))->toBe($expectedIds);
})->with([
    'is any of' => ['is_any_of', ['At Acme']],
    'is none of keeps the unlinked' => ['is_none_of', ['At Globex', 'Freelance']],
]);

it('filters assignees by member ids and by me, as the api does', function (): void {
    $colleague = filterBarMember($this->user);
    $mine = filterBarRecord($this->user, Task::class, 'Mine');
    $theirs = filterBarRecord($this->user, Task::class, 'Theirs');
    $nobodys = filterBarRecord($this->user, Task::class, 'Nobodys');
    $mine->assignees()->attach($this->user);
    $theirs->assignees()->attach($colleague);
    $compile = fn (array $rules): array => (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::Task, $this->user), $this->user, 'UTC');
    $me = [['field' => 'assignees', 'condition' => 'is_any_of', 'value' => ['@me']]];
    $notThem = [['field' => 'assignees', 'condition' => 'is_none_of', 'value' => [$colleague->getKey()]]];

    $meShown = livewire(ManageTasks::class)->set('tableFilters.conditions.rules', $me);
    $notThemShown = livewire(ManageTasks::class)->set('tableFilters.conditions.rules', $notThem);

    Sanctum::actingAs($this->user);

    expect($meShown->instance()->getTableRecords()->pluck('title')->all())->toBe(['Mine'])
        ->and($notThemShown->instance()->getTableRecords()->pluck('title')->sort()->values()->all())->toBe(['Mine', 'Nobodys'])
        ->and(filterBarApiIds($this, 'tasks', $compile($me)))->toBe([$mine->getKey()])
        ->and(filterBarApiIds($this, 'tasks', $compile($notThem)))->toBe(collect([$mine, $nobodys])->map->getKey()->sort()->values()->all());
});

it('gives each member their own records when two members apply one shared me rule', function (): void {
    $colleague = filterBarMember($this->user);
    filterBarRecord($this->user, Company::class, 'By owner');
    filterBarRecord($this->user, Company::class, 'By colleague', ['creator_id' => $colleague->getKey()]);
    $rules = [['field' => 'creator', 'condition' => 'is_any_of', 'value' => ['@me']]];

    $owner = filterBarShownNames(livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules));

    $this->actingAs($colleague);
    $other = filterBarShownNames(livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules));

    expect($owner)->toBe(['By owner'])->and($other)->toBe(['By colleague']);
});

it('filters a record custom field by record ids as the api does', function (string $condition, array $expected): void {
    filterBarField($this->user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
    $acme = filterBarRecord($this->user, Company::class, 'Acme');
    $globex = filterBarRecord($this->user, Company::class, 'Globex');
    $records = [
        filterBarRecord($this->user, Opportunity::class, 'With Acme', values: ['partner' => [$acme->getKey()]]),
        filterBarRecord($this->user, Opportunity::class, 'With Globex', values: ['partner' => [$globex->getKey()]]),
        filterBarRecord($this->user, Opportunity::class, 'Alone'),
    ];
    $rules = [['field' => 'custom_fields.partner', 'condition' => $condition, 'value' => [$acme->getKey()]]];
    $tree = (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::Opportunity, $this->user), $this->user, 'UTC');
    $expectedIds = collect($records)->filter(fn (Model $record): bool => in_array($record->name, $expected, true))->map->getKey()->sort()->values()->all();

    $shown = livewire(ListOpportunities::class)->set('tableFilters.conditions.rules', $rules);

    Sanctum::actingAs($this->user);

    expect(filterBarShownNames($shown))->toBe(collect($expected)->sort()->values()->all())
        ->and(filterBarApiIds($this, 'opportunities', $tree))->toBe($expectedIds);
})->with([
    'has any of' => ['has_any_of', ['With Acme']],
    'has none of keeps the empty' => ['has_none_of', ['With Globex', 'Alone']],
]);

it('treats a record custom field link to a deleted record as no link in the bar', function (string $condition, string $values, array $expected): void {
    filterBarField($this->user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
    $live = filterBarRecord($this->user, Company::class, 'Live');
    $gone = filterBarRecord($this->user, Company::class, 'Gone');
    filterBarRecord($this->user, Opportunity::class, 'Orphaned', values: ['partner' => [$gone->getKey()]]);
    filterBarRecord($this->user, Opportunity::class, 'Mixed', values: ['partner' => [$live->getKey(), $gone->getKey()]]);
    filterBarRecord($this->user, Opportunity::class, 'Alone');
    $gone->delete();
    $ids = ['none' => [], 'live' => [$live->getKey()], 'gone' => [$gone->getKey()], 'gone and live' => [$gone->getKey(), $live->getKey()]][$values];

    $shown = livewire(ListOpportunities::class)->call('setFilterRules', [['field' => 'custom_fields.partner', 'condition' => $condition, 'value' => $ids]]);

    expect(filterBarShownNames($shown))->toBe($expected);
})->with([
    'is empty' => ['is_empty', 'none', ['Alone', 'Orphaned']],
    'is not empty' => ['is_not_empty', 'none', ['Mixed']],
    'has any of a live record' => ['has_any_of', 'live', ['Mixed']],
    'has any of a deleted record' => ['has_any_of', 'gone', []],
    'has none of a deleted record' => ['has_none_of', 'gone', ['Alone', 'Mixed', 'Orphaned']],
    'has none of a deleted and a live record' => ['has_none_of', 'gone and live', ['Alone', 'Orphaned']],
]);

it('shows every record for a link rule whose ids are not usable', function (string $field, mixed $value): void {
    filterBarLinkedPeople($this->user);

    livewire(ListPeople::class)
        ->set('tableFilters.conditions.rules', [['field' => $field, 'condition' => 'is_any_of', 'value' => $value]])
        ->assertCountTableRecords(3);
})->with([
    'a string' => ['company', 'x'],
    'an empty list' => ['company', []],
    'not a record id' => ['company', ['nope']],
    'me on a relation' => ['company', ['@me']],
    'too many ids' => ['company', array_fill(0, 101, '01J8Z4Y6T5Q2M9N3B7K1W0X8VD')],
    'nested lists' => ['company', [['01J8Z4Y6T5Q2M9N3B7K1W0X8VD']]],
]);

it('bounds a relation filter by the workspace the member opened', function (): void {
    $first = filterBarLinkedPeople($this->user);
    $second = Workspace::factory()->create(['personal_workspace' => false, 'user_id' => $this->user->id]);
    $this->user->ownedWorkspaces()->save($second);
    $this->user->switchWorkspace($second);
    Filament::setTenant($second);
    $user = $this->user->refresh();
    $inSecond = Company::factory()->recycle([$user, $second])->create(['name' => 'Second Co']);
    People::factory()->recycle([$user, $second])->create(['name' => 'At Second', 'company_id' => $inSecond->getKey()]);
    People::factory()->recycle([$user, $second])->create(['name' => 'Lone Second']);

    $none = livewire(ListPeople::class)->set('tableFilters.conditions.rules', [['field' => 'company', 'condition' => 'is_none_of', 'value' => [$inSecond->getKey()]]]);
    $foreign = livewire(ListPeople::class)->set('tableFilters.conditions.rules', [['field' => 'company', 'condition' => 'is_any_of', 'value' => [$first[0]->getKey()]]]);

    $mixed = livewire(ListPeople::class)->set('tableFilters.conditions.rules', [['field' => 'company', 'condition' => 'is_any_of', 'value' => [$first[0]->getKey(), $inSecond->getKey()]]]);

    expect(filterBarShownNames($none))->toBe(['Lone Second'])
        ->and(filterBarShownNames($foreign))->toBe(['At Second', 'Lone Second'])
        ->and($foreign->get('tableFilters.conditions.rules'))->toBe([])
        ->and($foreign->html())->not->toContain($first[0]->name)
        ->and($mixed->get('tableFilters.conditions.rules.0.value'))->toBe([$inSecond->getKey()])
        ->and(filterBarShownNames($mixed))->toBe(['At Second']);
});

function filterBarForeignCompany(string $name): Company
{
    $stranger = User::factory()->withWorkspace()->create();

    return Company::withoutEvents(fn (): Company => Company::factory()->create([
        'name' => $name,
        'workspace_id' => $stranger->currentWorkspace->getKey(),
        'account_owner_id' => $stranger->getKey(),
    ]));
}

function filterBarSearch(mixed $test, string $page, string $field, string $search = ''): array
{
    return livewire($page)->instance()->searchFilterOptions($field, $search);
}

it('searches the records of a relation field by name inside the workspace', function (): void {
    filterBarRecord($this->user, Company::class, 'Acme Local');
    filterBarRecord($this->user, Company::class, 'Globex');
    filterBarForeignCompany('Acme Foreign');

    $found = filterBarSearch($this, ListPeople::class, 'company', 'acme');

    expect(array_column($found, 'label'))->toBe(['Acme Local'])
        ->and(array_keys($found[0]))->toBe(['id', 'label', 'image', 'icon', 'circular'])
        ->and(array_column(filterBarSearch($this, ListPeople::class, 'company'), 'label'))->toBe(['Acme Local', 'Globex']);
});

it('keeps a lookup inside the user workspace even when the panel tenant is another', function (string $page, string $field): void {
    filterBarField($this->user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
    $other = Workspace::factory()->create(['personal_workspace' => false]);
    $foreign = Company::withoutEvents(fn (): Company => Company::factory()->create(['name' => 'Zeta Foreign', 'workspace_id' => $other->getKey()]));
    $component = livewire($page);

    Filament::setTenant($other);

    expect($foreign->workspace_id)->toBe($other->getKey())
        ->and($component->instance()->searchFilterOptions($field, 'zeta'))->toBe([]);
})->with([
    'a relation field' => [ListPeople::class, 'company'],
    'a record custom field' => [ListOpportunities::class, 'custom_fields.partner'],
]);

it('searches members of this workspace and no one else', function (): void {
    $colleague = filterBarMember($this->user);
    $colleague->update(['name' => 'Casey Colleague']);
    User::factory()->withWorkspace()->create(['name' => 'Casey Stranger']);

    $found = filterBarSearch($this, ListCompanies::class, 'creator', 'casey');

    expect($found)->toBe([[
        'id' => $colleague->getKey(),
        'label' => 'Casey Colleague',
        'image' => $colleague->getFilamentAvatarUrl(),
        'icon' => null,
        'circular' => true,
    ]]);
});

it('leaves the viewer out of a member search because the me entry stands for them', function (): void {
    $colleague = filterBarMember($this->user);
    $colleague->update(['name' => "{$this->user->name} Junior"]);

    $found = filterBarSearch($this, ListCompanies::class, 'creator', $this->user->name);

    expect(array_column($found, 'id'))->toBe([$colleague->getKey()])
        ->and(array_column(filterBarSearch($this, ListCompanies::class, 'creator'), 'id'))->not->toContain($this->user->getKey());
});

it('marks a company with its logo or the company icon and a person with the person icon', function (): void {
    $logo = filterBarRecord($this->user, Company::class, 'Acme Logo');
    $logo->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    filterBarRecord($this->user, Company::class, 'Acme Plain');
    filterBarRecord($this->user, People::class, 'Acme Person');
    filterBarField($this->user, 'opportunity', 'contact_pick', CustomFieldType::RECORD->value, lookup: 'people');

    $companies = collect(filterBarSearch($this, ListPeople::class, 'company', 'acme'))->keyBy('label');
    $people = filterBarSearch($this, ListOpportunities::class, 'custom_fields.contact_pick', 'acme');

    expect($companies['Acme Logo'])->toMatchArray(['image' => $logo->fresh()->logo, 'icon' => CrmEntity::Company->iconPath(), 'circular' => false])
        ->and($companies['Acme Plain'])->toMatchArray(['image' => null, 'icon' => CrmEntity::Company->iconPath(), 'circular' => false])
        ->and($people[0])->toMatchArray(['label' => 'Acme Person', 'image' => null, 'icon' => CrmEntity::People->iconPath(), 'circular' => true]);
});

it('looks up company logos without a query per company', function (): void {
    foreach (range(1, 6) as $number) {
        filterBarRecord($this->user, Company::class, "Logo {$number}")
            ->addMediaFromString(onePixelPng())->usingFileName("logo-{$number}.png")->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    }
    $component = livewire(ListPeople::class);

    DB::enableQueryLog();
    $found = $component->instance()->searchFilterOptions('company', 'logo');
    $queries = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], 'from "media"'));
    DB::disableQueryLog();

    expect($found)->toHaveCount(6)->and($queries)->toHaveCount(1);
});

it('reads only the columns and the logo collection a company option needs', function (): void {
    filterBarRecord($this->user, Company::class, 'Logo Co')
        ->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    $component = livewire(ListPeople::class);

    DB::enableQueryLog();
    $component->instance()->searchFilterOptions('company', 'logo');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->first(fn (string $query): bool => str_contains($query, 'from "companies"')))
        ->toContain('select "companies"."id", "companies"."name", "companies"."deleted_at" from "companies"')
        ->and($queries->first(fn (string $query): bool => str_contains($query, 'from "media"')))
        ->toContain('"collection_name" = ?');
});

it('reads no media for records that carry no logo', function (string $page, string $entity, string $lookup, string $related): void {
    filterBarField($this->user, $entity, 'pick', CustomFieldType::RECORD->value, lookup: $lookup);
    foreach (range(1, 3) as $number) {
        filterBarRecord($this->user, $related, "Logo {$number}");
    }
    $component = livewire($page);

    DB::enableQueryLog();
    $found = $component->instance()->searchFilterOptions('custom_fields.pick', 'logo');
    $media = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], '"media"'));
    DB::disableQueryLog();

    expect($found)->toHaveCount(3)->and($media)->toHaveCount(0);
})->with([
    'people on an opportunity' => [ListOpportunities::class, 'opportunity', 'people', People::class],
    'opportunities on a person' => [ListPeople::class, 'people', 'opportunity', Opportunity::class],
]);

it('searches the lookup records of a record custom field', function (): void {
    filterBarField($this->user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
    filterBarRecord($this->user, Company::class, 'Acme Local');
    filterBarForeignCompany('Acme Foreign');

    expect(array_column(filterBarSearch($this, ListOpportunities::class, 'custom_fields.partner', 'acme'), 'label'))->toBe(['Acme Local']);
});

it('caps a search at twenty options', function (): void {
    Company::factory()->count(25)->recycle([$this->user, $this->workspace])->create();

    expect(filterBarSearch($this, ListPeople::class, 'company'))->toHaveCount(20);
});

it('treats a search term as text and not as a pattern', function (string $term, array $expected): void {
    foreach (['A_B', 'AXB', '100%', 'Plain'] as $name) {
        filterBarRecord($this->user, Company::class, $name);
    }

    expect(array_column(filterBarSearch($this, ListPeople::class, 'company', $term), 'label'))->toBe($expected);
})->with([
    'an underscore' => ['A_B', ['A_B']],
    'a percent sign' => ['%', ['100%']],
    'a backslash' => ['\\', []],
    'a null byte' => ["A\0B", []],
]);

it('returns nothing for a field that holds no records', function (string $field): void {
    filterBarRecord($this->user, Company::class, 'Acme');

    expect(filterBarSearch($this, ListPeople::class, $field, 'a'))->toBe([]);
})->with([
    'unknown' => ['nope'],
    'text' => ['name'],
    'date' => ['created_at'],
    'choice' => ['creation_source'],
    'a field of another entity' => ['stale_days'],
    'a custom field prefix alone' => ['custom_fields.'],
]);

it('returns nothing for a viewer the policy keeps out of the related records', function (): void {
    filterBarRecord($this->user, Company::class, 'Acme');
    $component = livewire(ListPeople::class);
    $this->user->forceFill(['email_verified_at' => null])->save();

    expect($component->instance()->searchFilterOptions('company', ''))->toBe([]);
});

it('drops a phone value the shared filter would reject', function (string $value): void {
    [$page, , , $records] = filterBarScenario('phones', $this->user);

    livewire($page)
        ->set('tableFilters.conditions.rules', [['field' => 'custom_fields.mobile', 'condition' => 'is', 'value' => $value]])
        ->assertCountTableRecords(count($records));
})->with(['+abc', '+1 (555)', '+', '++1415', '+1415555019x', '+1234567890123456789']);

it('still narrows by a full phone number', function (): void {
    [$page, , , $records] = filterBarScenario('phones', $this->user);

    livewire($page)
        ->set('tableFilters.conditions.rules', [['field' => 'custom_fields.mobile', 'condition' => 'is', 'value' => '+1 415 555 0100']])
        ->assertCanSeeTableRecords([$records[0]])
        ->assertCountTableRecords(1);
});

it('never gives a native field a raw lang key for a label', function (string $page, string $model): void {
    $entity = CrmEntity::tryFromModel(new $model);
    $labelled = livewire($page)->instance()->filterFieldCatalog();

    foreach ([FilterFieldCatalog::for($entity, $this->user), $labelled] as $catalog) {
        foreach ($catalog as $field) {
            expect($field->label)->not->toStartWith('filament/')->not->toBeEmpty();
        }
    }
})->with('filter bar pages');

it('words a fallback label as the column header', function (): void {
    $company = FilterFieldCatalog::for(CrmEntity::Company, $this->user);
    $task = FilterFieldCatalog::for(CrmEntity::Task, $this->user);

    expect($company['name']->label)->toBe('Company')
        ->and($company['created_at']->label)->toBe('Creation Date')
        ->and($company['updated_at']->label)->toBe('Last Update')
        ->and($company['creator']->label)->toBe('Created By')
        ->and($company['accountOwner']->label)->toBe('Account Owner')
        ->and($task['title']->label)->toBe('Title')
        ->and($task['created_at']->label)->toBe('Created at')
        ->and($task['assignees']->label)->toBe('Assignees');
});

dataset('unusable rules', [
    'unknown field' => [['field' => 'nope', 'condition' => 'is_empty']],
    'unknown condition' => [['field' => 'name', 'condition' => 'nope', 'value' => 'x']],
    'condition the field does not offer' => [['field' => 'name', 'condition' => 'is_checked']],
    'value of the wrong shape' => [['field' => 'name', 'condition' => 'contains', 'value' => ['x']]],
    'unknown creation source' => [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['bogus']]],
    'one hundred and one values' => [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => array_map(fn (int $i): string => "v{$i}", range(1, 101))]],
    'empty string in a list' => [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['', 'api']]],
    'three thousand characters' => [['field' => 'name', 'condition' => 'contains', 'value' => str_repeat('a', 3000)]],
    'not a rule' => ['x'],
]);

it('drops a rule that cannot apply and keeps the others, from a view, the url and a direct set', function (mixed $unusable, string $how): void {
    filterBarScenario('companies', $this->user);
    $good = ['field' => 'name', 'condition' => 'contains', 'value' => 'glo'];

    $component = filterBarOpen($this, $how, ListCompanies::class, CrmEntity::Company, filterBarRulesState([$unusable, $good]));

    expect(filterBarShownNames($component))->toBe(['Globex'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe([$good]);
})->with('unusable rules')->with(['view', 'url', 'set']);

it('drops a text value past the cap on a multi text field and still renders', function (string $how): void {
    filterBarRecord($this->user, People::class, 'Ann', values: ['emails' => ['ann@acme.com']]);
    $long = ['field' => 'custom_fields.emails', 'condition' => 'contains', 'value' => str_repeat('a', 300)];
    $good = ['field' => 'custom_fields.emails', 'condition' => 'contains', 'value' => 'acme'];

    $component = filterBarOpen($this, $how, ListPeople::class, CrmEntity::People, filterBarRulesState([$long, $good]))->assertOk();

    expect($component->get('tableFilters.conditions.rules'))->toBe([$good])
        ->and(filterBarShownNames($component))->toBe(['Ann']);
})->with(['view', 'url', 'set']);

it('drops text that holds a nul byte', function (string $how): void {
    filterBarScenario('companies', $this->user);
    $good = ['field' => 'name', 'condition' => 'contains', 'value' => 'glo'];
    $nul = ['field' => 'name', 'condition' => 'contains', 'value' => "a\0b"];

    $component = filterBarOpen($this, $how, ListCompanies::class, CrmEntity::Company, filterBarRulesState([$nul, $good]));

    expect(filterBarShownNames($component))->toBe(['Globex'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe([$good]);
})->with(['url', 'set']);

it('drops a stale option id from a list and the rule when none remain', function (string $how): void {
    [$page] = filterBarScenario('stages', $this->user);
    $first = filterBarOptionId($this->user, 'opportunity', 'stage', 0);
    $stale = '01J8Z4Y6T5Q2M9N3B7K1W0X8VD';
    $rules = [
        ['field' => 'custom_fields.stage', 'condition' => 'is_none_of', 'value' => [$stale]],
        ['field' => 'custom_fields.stage', 'condition' => 'is_any_of', 'value' => [$stale, $first]],
    ];

    $component = filterBarOpen($this, $how, $page, CrmEntity::Opportunity, filterBarRulesState($rules));

    expect(filterBarShownNames($component))->toBe(['First'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe([['field' => 'custom_fields.stage', 'condition' => 'is_any_of', 'value' => [$first]]]);
})->with(['view', 'url', 'set']);

it('drops a member who left the workspace and keeps me', function (string $how): void {
    $stranger = User::factory()->create();
    $mine = filterBarRecord($this->user, Task::class, 'Mine');
    $mine->assignees()->attach($this->user);
    filterBarRecord($this->user, Task::class, 'Nobodys');
    $rules = [['field' => 'assignees', 'condition' => 'is_any_of', 'value' => [$stranger->getKey(), '@me']]];

    $component = filterBarOpen($this, $how, ManageTasks::class, CrmEntity::Task, filterBarRulesState($rules));

    expect(filterBarShownTitles($component))->toBe(['Mine'])
        ->and($component->get('tableFilters.conditions.rules.0.value'))->toBe(['@me']);
})->with(['view', 'url', 'set']);

it('drops a record of another workspace and never prints its name', function (string $how): void {
    $foreign = filterBarForeignCompany('Hostile Holdings');
    filterBarRecord($this->user, People::class, 'Local Lead');
    $rules = [['field' => 'company', 'condition' => 'is_any_of', 'value' => [$foreign->getKey()]]];

    $component = filterBarOpen($this, $how, ListPeople::class, CrmEntity::People, filterBarRulesState($rules));

    expect(filterBarShownNames($component))->toBe(['Local Lead'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe([])
        ->and($component->html())->not->toContain('Hostile Holdings');
})->with(['view', 'url', 'set']);

it('keeps the same rule on a free text tags field without looking its values up', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    filterBarRecord($this->user, Company::class, 'Vip', values: ['labels' => ['vip']]);
    filterBarRecord($this->user, Company::class, 'Plain');
    $rules = [['field' => 'custom_fields.labels', 'condition' => 'has_any_of', 'value' => ['vip']]];

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules);

    expect(filterBarShownNames($component))->toBe(['Vip'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe($rules);
});

it('reads the distinct stored tags once when the company list renders', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    filterBarRecord($this->user, Company::class, 'Vip', values: ['labels' => ['vip']]);

    DB::enableQueryLog();
    livewire(ListCompanies::class);
    $tagScans = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], 'select distinct on (lower(btrim(element)))'));
    DB::disableQueryLog();

    expect($tagScans)->toHaveCount(1);
});

it('searches member options without reading the stored tags of any attribute', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    filterBarRecord($this->user, Company::class, 'Vip', values: ['labels' => ['vip']]);
    $component = livewire(ListCompanies::class);
    Once::flush();

    DB::enableQueryLog();
    $component->instance()->searchFilterOptions('accountOwner', 'a');
    $tagScans = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], 'select distinct on (lower(btrim(element)))'));
    DB::disableQueryLog();

    expect($tagScans)->toHaveCount(0);
});

it('keeps the first twenty conditions and drops the rest', function (): void {
    filterBarScenario('companies', $this->user);
    $rule = ['field' => 'name', 'condition' => 'contains', 'value' => 'o'];

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', array_fill(0, 500, $rule));

    expect($component->get('tableFilters.conditions.rules'))->toHaveCount(FilterTree::MAX_CONDITIONS)
        ->and(filterBarShownNames($component))->toBe(['Acme Robotics', 'Globex']);
});

it('counts a relative period as two conditions', function (): void {
    $rules = array_map(
        fn (int $amount): array => ['field' => 'created_at', 'condition' => 'in_the_past', 'value' => ['amount' => $amount, 'unit' => 'days']],
        range(1, 11),
    );
    filterBarRecord($this->user, Company::class, 'Today');

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules);

    expect(array_column(array_column($component->get('tableFilters.conditions.rules'), 'value'), 'amount'))->toBe(range(1, 10))
        ->and(filterBarShownNames($component))->toBe(['Today']);
});

it('renders a page for a hostile query string or direct state', function (string $query): void {
    $this->get(ListCompanies::getUrl(['tenant' => $this->workspace]).'?'.$query)->assertSuccessful();
})->with([
    'conditions a string' => 'filters[conditions]=x',
    'rules a string' => 'filters[conditions][rules]=x',
    'a rule a string' => 'filters[conditions][rules][0]=x',
    'a value as a deep array' => 'filters[conditions][rules][0][field]=name&filters[conditions][rules][0][condition]=contains&filters[conditions][rules][0][value][a][b][c]=x',
]);

it('survives a client that sets a filter to a string', function (string $page): void {
    livewire($page)
        ->set('tableFilters', ['trashed' => 'x', 'conditions' => 'y'])
        ->assertOk()
        ->assertSet('tableFilters.trashed.value', null)
        ->assertSet('tableFilters.conditions.rules', []);
})->with('filter bar pages');

it('survives a client that calls the filter setters with nothing or the wrong type', function (string $page, string $model, string $method, array $arguments, ?int $trashed): void {
    livewire($page)
        ->call('setDeletedRecordsFilter', 'included')
        ->call($method, ...$arguments)
        ->assertOk()
        ->assertSet('tableFilters.trashed.value', $trashed)
        ->assertSet('tableFilters.conditions.rules', []);
})->with('filter bar pages')->with([
    'deleted mode missing' => ['setDeletedRecordsFilter', [], null],
    'deleted mode an array' => ['setDeletedRecordsFilter', [['x']], null],
    'rules missing' => ['setFilterRules', [], 1],
    'rules a string' => ['setFilterRules', ['x'], 1],
]);

it('clears a rule that was set when the client sends rules that are not a list', function (string $page, string $model, array $arguments): void {
    $rule = ['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['api']];

    livewire($page)
        ->call('setFilterRules', [$rule])
        ->assertSet('tableFilters.conditions.rules', [$rule])
        ->call('setDeletedRecordsFilter', 'included')
        ->call('setFilterRules', ...$arguments)
        ->assertOk()
        ->assertSet('tableFilters.conditions.rules', [])
        ->assertSet('tableFilters.trashed.value', 1);
})->with('filter bar pages')->with([
    'rules missing' => [[]],
    'rules a string' => [['x']],
]);
