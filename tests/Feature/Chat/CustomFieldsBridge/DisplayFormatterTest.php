<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Task;
use App\Models\User;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(CustomFieldsDisplayFormatter::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

it('formats a single-choice field with the option label, not the id', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->getKey();
    $statusField = CustomField::query()
        ->where('tenant_id', $teamId)
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();
    $doneId = $statusField->options->firstWhere('name', 'Done')->id;

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['status' => $doneId], oldModel: null);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['label' => 'Status', 'new' => 'Done']);
});

it('formats a date-time field as a localized date string', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['due_date' => '2026-05-20T14:00:00Z'], oldModel: null);

    expect($rows[0]['label'])->toBe('Due Date')
        ->and($rows[0]['new'])->toContain('May 20, 2026');
});

it('formats rich-text fields by stripping HTML for the proposal card', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['description' => '<p>Hello <strong>world</strong></p>'], oldModel: null);

    expect($rows[0]['new'])->toBe('Hello world');
});

it('includes the old value for updates with a current value on the model', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $task = Task::factory()->for($team)->create(['title' => 'T']);

    $descField = CustomField::query()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    $task->saveCustomFieldValue($descField, '<p>Old text</p>');

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['description' => '<p>New text</p>'], oldModel: $task->fresh());

    expect($rows[0])->toMatchArray([
        'label' => 'Description',
        'old' => 'Old text',
        'new' => 'New text',
    ]);
});

/**
 * The old side of a multi-value diff. `json_value` is cast to a Collection, so
 * every is_array() branch in the formatter misses it and the raw Collection
 * stringifies to its own JSON: without the unwrap, this row reads
 * `["old.example.com"]` before the arrow and `new.example.com` after it, in the
 * same proposal card. The stored path already unwraps; this pins the proposed
 * path so the two cannot diverge again.
 */
it('renders the old value of a multi-value field as its members, not raw json', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $company = Company::factory()->for($team)->create(['name' => 'Acme']);

    $domains = CustomField::query()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->firstOrFail();

    $company->saveCustomFieldValue($domains, ['old.example.com']);

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'company', cleanFields: ['domains' => ['new.example.com']], oldModel: $company->fresh());

    expect($rows[0])->toMatchArray([
        'label' => 'Domains',
        'old' => 'old.example.com',
        'new' => 'new.example.com',
    ]);
});

it('returns an empty array when no custom_fields are submitted', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: [], oldModel: null);

    expect($rows)->toBe([]);
});

it('renders a record custom field on the proposal card as the record name, not its id', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $company = Company::factory()->create(['team_id' => $user->currentTeam->getKey(), 'name' => 'Globex']);

    $section = CustomFieldSection::query()->create([
        'tenant_id' => $user->currentTeam->getKey(),
        'entity_type' => 'task',
        'name' => 'Links',
        'code' => 'links',
        'type' => 'section',
        'sort_order' => 98,
        'active' => true,
    ]);

    $field = CustomField::query()->create([
        'tenant_id' => $user->currentTeam->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'task',
        'code' => 'linked_company',
        'name' => 'Linked Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    TenantContextService::setTenantId($user->currentTeam->getKey());

    try {
        $rows = resolve(CustomFieldsDisplayFormatter::class)
            ->format($user, 'task', [$field->code => [$company->getKey()]], null);
    } finally {
        TenantContextService::setTenantId(null);
    }

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['new'])->toBe('Globex')
        ->and($rows[0]['values'])->toBe(['Globex']);
});

it('names the record on a stored record card, not its id', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $company = Company::factory()->create(['team_id' => $user->currentTeam->getKey(), 'name' => 'Initech']);

    $section = CustomFieldSection::query()->create([
        'tenant_id' => $user->currentTeam->getKey(),
        'entity_type' => 'task',
        'name' => 'Links',
        'code' => 'links',
        'type' => 'section',
        'sort_order' => 97,
        'active' => true,
    ]);

    $field = CustomField::query()->create([
        'tenant_id' => $user->currentTeam->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'task',
        'code' => 'linked_company',
        'name' => 'Linked Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $task = Task::factory()->create(['team_id' => $user->currentTeam->getKey()]);
    $task->saveCustomFieldValue($field, [$company->getKey()]);

    TenantContextService::setTenantId($user->currentTeam->getKey());

    try {
        $rows = resolve(CustomFieldsDisplayFormatter::class)->formatStored(
            $task->fresh('customFieldValues.customField.options'),
            [$field->fresh('options')],
            200,
        );
    } finally {
        TenantContextService::setTenantId(null);
    }

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['value'])->toBe('Initech')
        ->and($rows[0]['values'])->toBe(['Initech']);
});
