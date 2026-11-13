<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\Helpers\WorkspaceCustomField;

function filterBarField(User $user, string $entity, string $code, string $type, array $options = [], ?string $lookup = null): void
{
    resolve(CreateCustomField::class)->execute($user, array_filter([
        'entity_type' => $entity,
        'name' => ucfirst($code),
        'code' => $code,
        'type' => $type,
        'options' => $options === [] ? null : array_map(static fn (string $name): array => ['name' => $name], $options),
        'lookup_type' => $lookup,
    ]));
}

function filterBarRecord(User $user, string $model, string $name, array $attributes = [], array $values = []): Model
{
    $column = $model === Task::class || $model === Note::class ? 'title' : 'name';
    $record = $model::factory()->recycle([$user, $user->currentWorkspace])->create([$column => $name, ...$attributes]);
    $entity = CrmEntity::tryFromModel($record)->value;

    foreach ($values as $code => $value) {
        $record->saveCustomFieldValue(WorkspaceCustomField::byCode($user->currentWorkspace->getKey(), $entity, $code), $value);
    }

    return $record;
}

function filterBarOptionId(User $user, string $entity, string $code, int $index): string
{
    return (string) WorkspaceCustomField::byCode($user->currentWorkspace->getKey(), $entity, $code)->options->sortBy('sort_order')->values()[$index]->getKey();
}

function filterBarScenario(string $scenario, User $user): array
{
    $record = fn (string $model, string $name, array $attributes = [], array $values = []): Model => filterBarRecord($user, $model, $name, $attributes, $values);

    return match ($scenario) {
        'companies' => [ListCompanies::class, 'companies', ['Acme Robotics', 'Globex', 'Initech'], array_map(fn (string $name): Model => $record(Company::class, $name), ['Acme Robotics', 'Globex', 'Initech'])],
        'amounts' => [ListOpportunities::class, 'opportunities', ['Small', 'Mid', 'Big', 'None'], [
            $record(Opportunity::class, 'Small', values: ['amount' => 100]),
            $record(Opportunity::class, 'Mid', values: ['amount' => 1000]),
            $record(Opportunity::class, 'Big', values: ['amount' => 5000]),
            $record(Opportunity::class, 'None'),
        ]],
        'stages' => [ListOpportunities::class, 'opportunities', ['First', 'Second', 'Unstaged'], [
            $record(Opportunity::class, 'First', values: ['stage' => filterBarOptionId($user, 'opportunity', 'stage', 0)]),
            $record(Opportunity::class, 'Second', values: ['stage' => filterBarOptionId($user, 'opportunity', 'stage', 1)]),
            $record(Opportunity::class, 'Unstaged'),
        ]],
        'sources' => [ListCompanies::class, 'companies', ['Typed', 'Called'], [
            $record(Company::class, 'Typed', ['creation_source' => CreationSource::WEB]),
            $record(Company::class, 'Called', ['creation_source' => CreationSource::API]),
        ]],
        'tags' => [ListCompanies::class, 'companies', ['Red', 'Blue', 'Plain'], (function () use ($user, $record): array {
            filterBarField($user, 'company', 'palette', CustomFieldType::MULTI_SELECT->value, ['Red', 'Blue']);

            return [
                $record(Company::class, 'Red', values: ['palette' => [filterBarOptionId($user, 'company', 'palette', 0)]]),
                $record(Company::class, 'Blue', values: ['palette' => [filterBarOptionId($user, 'company', 'palette', 1)]]),
                $record(Company::class, 'Plain'),
            ];
        })()],
        'merge' => [ListCompanies::class, 'companies', ['Acme Corp', 'Acme Robotics', 'Corp Holdings'], array_map(fn (string $name): Model => $record(Company::class, $name), ['Acme Corp', 'Acme Robotics', 'Corp Holdings'])],
        'seats' => [ListOpportunities::class, 'opportunities', ['Few', 'Many', 'Unset'], (function () use ($user, $record): array {
            filterBarField($user, 'opportunity', 'seats', CustomFieldType::NUMBER->value);

            return [
                $record(Opportunity::class, 'Few', values: ['seats' => 10]),
                $record(Opportunity::class, 'Many', values: ['seats' => 5000]),
                $record(Opportunity::class, 'Unset'),
            ];
        })()],
        'phones' => [ListPeople::class, 'people', ['Cell', 'Desk', 'Silent'], (function () use ($user, $record): array {
            filterBarField($user, 'people', 'mobile', CustomFieldType::PHONE->value);

            return [
                $record(People::class, 'Cell', values: ['mobile' => ['+14155550100']]),
                $record(People::class, 'Desk', values: ['mobile' => ['+14155550199']]),
                $record(People::class, 'Silent'),
            ];
        })()],
        'icp' => [ListCompanies::class, 'companies', ['Marked', 'Unmarked', 'Never'], [
            $record(Company::class, 'Marked', values: ['icp' => true]),
            $record(Company::class, 'Unmarked', values: ['icp' => false]),
            $record(Company::class, 'Never'),
        ]],
        'emails' => [ListPeople::class, 'people', ['Ann', 'Bob', 'Cy'], [
            $record(People::class, 'Ann', values: ['emails' => ['ann@acme.com']]),
            $record(People::class, 'Bob', values: ['emails' => ['bob@globex.com', 'robert@acme.org']]),
            $record(People::class, 'Cy'),
        ]],
        'jobs' => [ListPeople::class, 'people', ['Chief', 'Nobody'], [
            $record(People::class, 'Chief', values: ['job_title' => 'CTO']),
            $record(People::class, 'Nobody'),
        ]],
        'stale' => [ListOpportunities::class, 'opportunities', ['Fresh', 'Old'], (function () use ($record): array {
            $this->travelTo(now()->subDays(40));
            $old = $record(Opportunity::class, 'Old');
            $this->travelBack();

            return [$record(Opportunity::class, 'Fresh'), $old];
        })->call(test())],
    };
}

function filterBarApiIds(mixed $test, string $path, array $tree): array
{
    return collect($test->getJson("/api/v1/{$path}?".http_build_query(['filter' => $tree]))->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

function filterBarCreatedAtRecords(User $user): array
{
    $stamps = [
        'Dec31' => '2025-12-31 23:30:00',
        'Jan01' => '2026-01-01 00:30:00',
        'Feb15' => '2026-02-15 10:00:00',
        'Feb16' => '2026-02-16 00:30:00',
        'Mar01' => '2026-03-01 10:00:00',
        'Mar15' => '2026-03-15 10:00:00',
        'Mar16' => '2026-03-16 00:30:00',
        'Mar18' => '2026-03-18 18:00:00',
        'Mar19' => '2026-03-19 00:30:00',
        'Mar22' => '2026-03-22 23:30:00',
        'Mar23' => '2026-03-23 00:30:00',
        'Mar31' => '2026-03-31 23:30:00',
        'Apr01' => '2026-04-01 00:30:00',
        'Apr17' => '2026-04-17 10:00:00',
        'Apr18' => '2026-04-18 00:30:00',
    ];

    return array_map(fn (string $stamp, string $name): Model => filterBarRecord($user, Company::class, $name, ['created_at' => $stamp]), $stamps, array_keys($stamps));
}

function filterBarShownNames(mixed $component): array
{
    return $component->instance()->getTableRecords()->pluck('name')->sort()->values()->all();
}

function filterBarMember(User $owner): User
{
    $member = User::factory()->create(['current_workspace_id' => $owner->currentWorkspace->getKey()]);
    $owner->currentWorkspace->users()->attach($member, ['role' => 'member']);

    return $member;
}

function filterBarOpen(mixed $test, string $how, string $page, CrmEntity $entity, array $filters): mixed
{
    return match ($how) {
        'view' => livewire($page)->call('applySavedView', SavedView::factory()->for($test->user)->for($test->workspace)->create([
            'entity' => $entity,
            'state' => new TableStateData(filters: $filters),
        ])->getKey()),
        'url' => Livewire::withQueryParams(['filters' => $filters])->test($page),
        'set' => livewire($page)->set('tableFilters', $filters),
    };
}

function filterBarShownTitles(mixed $component): array
{
    return $component->instance()->getTableRecords()->pluck('title')->sort()->values()->all();
}

function filterBarRulesState(array $rules): array
{
    return ['conditions' => ['match' => 'all', 'rules' => $rules]];
}

function filterBarDeletedPair(User $user, string $model, string $name = 'Acme'): array
{
    return [filterBarRecord($user, $model, $name), tap(filterBarRecord($user, $model, $name))->delete()];
}
