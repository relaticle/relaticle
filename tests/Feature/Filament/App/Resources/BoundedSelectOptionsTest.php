<?php

declare(strict_types=1);

use App\Filament\Resources\NoteResource\Forms\NoteForm;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

/**
 * @return array<string, Select>
 */
function boundedSelectsIn(Schema $schema): array
{
    $selects = [];

    foreach ($schema->getComponents() as $component) {
        if ($component instanceof Select) {
            $selects[$component->getName()] = $component;
        }
    }

    return $selects;
}

// Regression pin, not a fail-first driver: these fields are `->multiple()`, and
// Filament's Select::isSearchable() falls back to isMultiple() when unset, so this
// assertion already holds before the explicit ->searchable() call is added. The
// explicit call pins the intended behavior against a future Filament default change.
it('marks the :dataset entity lookup searchable so Filament caps its options', function (string $form, string $field): void {
    $schema = $form::get(Schema::make(livewire(ManageTasks::class)->instance()));

    $select = boundedSelectsIn($schema)[$field] ?? null;

    expect($select)->not->toBeNull()
        ->and($select->isSearchable())->toBeTrue()
        ->and($select->isPreloaded())->toBeFalse();
})->with([
    'task companies' => [TaskForm::class, 'companies'],
    'task people' => [TaskForm::class, 'people'],
    'note companies' => [NoteForm::class, 'companies'],
    'note people' => [NoteForm::class, 'people'],
]);
