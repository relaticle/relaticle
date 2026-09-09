<?php

declare(strict_types=1);

use App\Http\Requests\Api\V1\Concerns\NormalizesCustomFields;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

mutates(NormalizesCustomFields::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    $this->status = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();
    Sanctum::actingAs($this->user, ['*']);
});

it('stores a task with a select value given as a label', function (): void {
    $this->postJson('/api/v1/tasks', ['title' => 'Rest label', 'custom_fields' => ['status' => 'Done']])
        ->assertCreated()
        ->assertJsonPath('data.attributes.custom_fields.status.label', 'Done');
});

it('returns 422 with the field key for an unknown label', function (): void {
    $this->postJson('/api/v1/tasks', ['title' => 'Rest bad', 'custom_fields' => ['status' => 'Blocked']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_fields.status']);
});

it('updates a task select value by label', function (): void {
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);

    $this->patchJson("/api/v1/tasks/{$task->getKey()}", ['custom_fields' => ['status' => 'in progress']])
        ->assertOk()
        ->assertJsonPath('data.attributes.custom_fields.status.label', 'In progress');
});
