<?php

declare(strict_types=1);

use App\Http\Concerns\NormalizesCustomFields;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

it('stores markdown note bodies as html', function (): void {
    $this->postJson('/api/v1/notes', ['title' => 'Md note', 'custom_fields' => ['body' => '**bold**']])
        ->assertCreated()
        ->assertJsonPath('data.attributes.custom_fields.body', fn (string $body): bool => str_contains($body, '<strong>bold</strong>'));
});

it('returns a record field as id and name pairs for an own-team company', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $company = Company::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Globex']);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($field, [$company->getKey()]);

    $this->getJson("/api/v1/tasks/{$task->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.attributes.custom_fields.related_company.0.id', $company->getKey())
        ->assertJsonPath('data.attributes.custom_fields.related_company.0.name', 'Globex');
});

it('accepts an option label on create and update for every CRM endpoint', function (string $entityType, string $endpoint, string $titleKey): void {
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => $entityType,
        'name' => 'Agent writes',
        'code' => 'agent_writes',
        'type' => 'section',
        'sort_order' => 97,
        'active' => true,
    ]);

    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => $entityType,
        'code' => 'tier',
        'name' => 'Tier',
        'type' => 'select',
        'sort_order' => 97,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    $gold = CustomFieldOption::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_id' => $field->getKey(),
        'name' => 'Gold',
        'sort_order' => 1,
    ]);
    CustomFieldOption::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_id' => $field->getKey(),
        'name' => 'Silver',
        'sort_order' => 2,
    ]);

    $created = $this->postJson("/api/v1/{$endpoint}", [
        $titleKey => 'Agent write probe',
        'custom_fields' => ['tier' => 'gold'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.custom_fields.tier.id', (string) $gold->getKey())
        ->assertJsonPath('data.attributes.custom_fields.tier.label', 'Gold');

    $this->patchJson("/api/v1/{$endpoint}/{$created->json('data.id')}", [
        'custom_fields' => ['tier' => 'Silver'],
    ])
        ->assertOk()
        ->assertJsonPath('data.attributes.custom_fields.tier.label', 'Silver');
})->with([
    'companies' => ['company', 'companies', 'name'],
    'people' => ['people', 'people', 'name'],
    'opportunities' => ['opportunity', 'opportunities', 'name'],
    'tasks' => ['task', 'tasks', 'title'],
    'notes' => ['note', 'notes', 'title'],
]);

it('resolves record names with a constant number of lookups, not one per row', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 92,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    $link = function (int $count) use ($field): void {
        Company::factory()->count($count)->create(['team_id' => $this->team->getKey()])
            ->each(fn (Company $company) => Task::factory()
                ->create(['team_id' => $this->team->getKey()])
                ->saveCustomFieldValue($field, [$company->getKey()]));
    };

    $listLookups = function () use (&$response): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/tasks?per_page=50')->assertOk();
        $count = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains($query['query'], 'from "companies"'),
        )->count();
        DB::disableQueryLog();

        return $count;
    };

    $link(3);
    $small = $listLookups();

    $link(9);
    $large = $listLookups();

    $names = collect($response->json('data'))
        ->pluck('attributes.custom_fields.related_company')
        ->filter()
        ->flatten(1)
        ->pluck('name')
        ->filter();

    expect($names)->toHaveCount(12)
        ->and($large)->toBe($small);
});
