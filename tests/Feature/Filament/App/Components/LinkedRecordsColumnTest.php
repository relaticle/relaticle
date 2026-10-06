<?php

declare(strict_types=1);

use App\Filament\Components\Tables\LinkedRecordsColumn;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;

mutates(LinkedRecordsColumn::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('shows the first linked record of a :dataset and offers the rest behind a count', function (string $model, string $page): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Northwind Traders']);
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Nora Hale']);
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Northwind renewal']);
    $record->companies()->attach($company);
    $record->people()->attach($person);
    $record->opportunities()->attach($opportunity);

    livewire($page)
        ->assertCanRenderTableColumn('relations')
        ->assertSeeInOrder(['Northwind Traders', '+2', 'Northwind Traders', 'Nora Hale', 'Northwind renewal'])
        ->assertSeeHtml('aria-label="Show all 3 linked records"')
        ->assertSeeHtml('href="'.CompanyResource::getUrl('view', ['record' => $company], tenant: $this->workspace).'"')
        ->assertSeeHtml('href="'.PeopleResource::getUrl('view', ['record' => $person], tenant: $this->workspace).'"')
        ->assertSeeHtml('href="'.OpportunityResource::getUrl('view', ['record' => $opportunity], tenant: $this->workspace).'"');
})->with([
    'note' => [Note::class, ManageNotes::class],
    'task' => [Task::class, ManageTasks::class],
]);

it('shows a single linked record without a count and nothing for a :dataset with none', function (string $model, string $page): void {
    $linked = $model::factory()->recycle([$this->user, $this->workspace])->create();
    $linked->people()->attach(People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Nora Hale']));
    $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($page)
        ->assertSee('Nora Hale')
        ->assertDontSeeHtml('fi-linked-records-cell-more')
        ->assertDontSeeHtml('fi-linked-records-cell-panel');
})->with([
    'note' => [Note::class, ManageNotes::class],
    'task' => [Task::class, ManageTasks::class],
]);
