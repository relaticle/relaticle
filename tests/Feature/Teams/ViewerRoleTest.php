<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;

mutates(User::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;

    $this->viewer = User::factory()->create();
    $this->team->users()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);
    $this->viewer->switchTeam($this->team);

    $this->editor = User::factory()->create();
    $this->team->users()->attach($this->editor, ['role' => TeamRole::Editor->value]);
    $this->editor->switchTeam($this->team);
});

test('viewer cannot create, update, delete, or restore records, but can view them', function (string $modelClass): void {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    $record = $modelClass::factory()->create(['team_id' => $this->team->id]);

    expect($this->viewer->can('create', $modelClass))->toBeFalse()
        ->and($this->viewer->can('update', $record))->toBeFalse()
        ->and($this->viewer->can('delete', $record))->toBeFalse()
        ->and($this->viewer->can('restore', $record))->toBeFalse()
        ->and($this->viewer->can('view', $record))->toBeTrue();
})->with([
    'company' => [Company::class],
    'people' => [People::class],
    'task' => [Task::class],
    'note' => [Note::class],
    'opportunity' => [Opportunity::class],
]);

test('viewer cannot bulk delete or bulk restore companies', function (): void {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    expect($this->viewer->can('deleteAny', Company::class))->toBeFalse()
        ->and($this->viewer->can('restoreAny', Company::class))->toBeFalse();
});

test('editor keeps write access to companies', function (): void {
    $this->actingAs($this->editor);
    Filament::setTenant($this->team);

    $company = Company::factory()->create(['team_id' => $this->team->id]);

    expect($this->editor->can('create', Company::class))->toBeTrue()
        ->and($this->editor->can('update', $company))->toBeTrue();
});

test('owner is never treated as a viewer', function (): void {
    expect($this->owner->isViewerOnTeamId($this->team->id))->toBeFalse();
});

test('viewer is read-only through the API too', function (): void {
    $token = $this->viewer->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/companies', ['name' => 'Blocked Co'])
        ->assertForbidden();
});
