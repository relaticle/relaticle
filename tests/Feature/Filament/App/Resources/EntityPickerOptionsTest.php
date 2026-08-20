<?php

declare(strict_types=1);

use App\Filament\Resources\NoteResource\Forms\NoteForm;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

mutates(TaskForm::class, NoteForm::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

/**
 * A searchable Filament select that is not preloaded returns null from
 * getOptionsFromRelationship() (Select.php:1065), which renders as an empty
 * dropdown until the user types. ->multiple() makes a select searchable by
 * default (Select.php:787), so every multiple relationship select needs
 * ->preload() to show an initial list.
 *
 * @return array<string, Select>
 */
function entityPickers(Schema $schema): array
{
    $schema->getComponents();

    $found = [];

    foreach ($schema->getComponents() as $component) {
        if ($component instanceof Select && $component->hasRelationship()) {
            $found[$component->getName()] = $component;
        }
    }

    return $found;
}

it('shows companies and people without typing on the task form', function (): void {
    Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Zeta Industries']);
    Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);
    People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Zoe Baker']);
    People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Adam Clark']);

    $livewire = app(ManageTasks::class);
    $pickers = entityPickers(TaskForm::get(Schema::make($livewire)->model(Task::class)));

    expect($pickers)->toHaveKeys(['companies', 'people']);

    foreach (['companies', 'people'] as $name) {
        expect($pickers[$name]->isPreloaded())->toBeTrue("{$name} must preload or it renders empty until the user types");
        expect($pickers[$name]->getOptionsFromRelationship())->not->toBeNull()
            ->and($pickers[$name]->getOptionsFromRelationship())->not->toBeEmpty();
    }

    expect(array_values($pickers['companies']->getOptionsFromRelationship()))->toBe(['Acme Corp', 'Zeta Industries'])
        ->and(array_values($pickers['people']->getOptionsFromRelationship()))->toBe(['Adam Clark', 'Zoe Baker']);
});

it('shows companies and people without typing on the note form', function (): void {
    Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);
    People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Adam Clark']);

    $livewire = app(ManageTasks::class);
    $pickers = entityPickers(NoteForm::get(Schema::make($livewire)->model(Note::class)));

    expect($pickers)->toHaveKeys(['companies', 'people']);

    foreach (['companies', 'people'] as $name) {
        expect($pickers[$name]->isPreloaded())->toBeTrue("{$name} must preload or it renders empty until the user types");
        expect($pickers[$name]->getOptionsFromRelationship())->not->toBeEmpty();
    }
});

it('scopes the preloaded options to the acting tenant', function (): void {
    Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Mine Co']);

    $otherUser = User::factory()->withTeam()->create();
    Company::factory()->recycle([$otherUser, $otherUser->currentTeam])->create(['name' => 'Theirs Co']);

    // TeamScope is installed by ApplyTenantScopes, which is panel middleware:
    // building the schema without a panel request would read every tenant's rows.
    $this->get(TaskResource::getUrl('index', tenant: $this->team));

    $pickers = entityPickers(TaskForm::get(Schema::make(app(ManageTasks::class))->model(Task::class)));
    $options = array_values($pickers['companies']->getOptionsFromRelationship());

    expect($options)->toContain('Mine Co')->not->toContain('Theirs Co');
});
