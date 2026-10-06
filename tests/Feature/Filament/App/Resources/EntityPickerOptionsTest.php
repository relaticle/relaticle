<?php

declare(strict_types=1);

use App\Filament\Components\Forms\LinkedRecordsSelect;
use App\Filament\Resources\NoteResource\Forms\NoteForm;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;

mutates(TaskForm::class, NoteForm::class, LinkedRecordsSelect::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

/**
 * @param  array<string>  $excludeFields
 */
function relationsPicker(string $form = NoteForm::class, string $model = Note::class, array $excludeFields = []): LinkedRecordsSelect
{
    return $form::get(Schema::make(app(ManageTasks::class))->model($model), $excludeFields)->getFlatFields()['relations'];
}

/**
 * @param  list<array{label: string, options: list<array{name: string}>}>  $groups
 * @return array<string, list<string>>
 */
function recordNamesByGroup(array $groups): array
{
    return collect($groups)
        ->mapWithKeys(fn (array $group): array => [$group['label'] => array_column($group['options'], 'name')])
        ->all();
}

it('offers the most recently updated companies, people and opportunities before the user types on the :dataset form', function (string $form, string $model): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp', 'updated_at' => now()->subDay()]);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Zeta Industries', 'updated_at' => now()]);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Adam Clark']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme renewal']);

    expect(recordNamesByGroup(relationsPicker($form, $model)->getRecordGroupsForJs()))->toBe([
        'Companies' => ['Zeta Industries', 'Acme Corp'],
        'People' => ['Adam Clark'],
        'Opportunities' => ['Acme renewal'],
    ]);
})->with([
    'task' => [TaskForm::class, Task::class],
    'note' => [NoteForm::class, Note::class],
]);

it('searches every record type on the server and treats wildcards as text', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Northwind Traders']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => '100% Organic']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Nora Northwind']);

    expect(recordNamesByGroup(relationsPicker()->getRecordGroupsForJs('northwind')))->toBe([
        'Companies' => ['Northwind Traders'],
        'People' => ['Nora Northwind'],
    ])->and(recordNamesByGroup(relationsPicker()->getRecordGroupsForJs('%')))->toBe([
        'Companies' => ['100% Organic'],
    ]);
});

it('offers a short list before the user types and a longer one for a search', function (): void {
    Company::factory()->count(12)->recycle([$this->user, $this->workspace])->create(['name' => 'Harbor Freight']);

    expect(relationsPicker()->getRecordGroupsForJs()[0]['options'])->toHaveCount(5)
        ->and(relationsPicker()->getRecordGroupsForJs('harbor')[0]['options'])->toHaveCount(10);
});

it('leaves the parent record type out of the relations picker', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create();
    People::factory()->recycle([$this->user, $this->workspace])->create();

    expect(array_column(relationsPicker(excludeFields: ['companies'])->getRecordGroupsForJs(), 'label'))->toBe(['People']);
});

it('never offers a record from another workspace, even outside a panel request', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Mine Co']);

    $otherUser = User::factory()->withWorkspace()->create();
    Company::factory()->recycle([$otherUser, $otherUser->currentWorkspace])->create(['name' => 'Theirs Co']);

    expect(recordNamesByGroup(relationsPicker()->getRecordGroupsForJs()))->toBe(['Companies' => ['Mine Co']])
        ->and(relationsPicker()->getRecordGroupsForJs('Theirs'))->toBe([]);
});

it('describes a person by their company and links each option to its record', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Adam Clark', 'company_id' => $company->id]);

    $options = collect(relationsPicker()->getRecordGroupsForJs())->flatMap(fn (array $group): array => $group['options'])->keyBy('value');

    expect($options["people:{$person->id}"])
        ->hint->toBe('Acme Corp')
        ->url->toBe(PeopleResource::getUrl('view', ['record' => $person], tenant: $this->workspace))
        ->and($options["company:{$company->id}"]['hint'])->toBeNull();
});
