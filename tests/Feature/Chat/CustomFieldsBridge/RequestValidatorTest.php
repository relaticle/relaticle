<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\CustomField;
use App\Models\User;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

it('returns the clean payload unchanged for simple string fields', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($user, 'task', ['description' => 'Hello']);

    expect($result->error)->toBeNull()
        ->and($result->cleanFields)->toBe(['description' => 'Hello']);
});

it('translates single-choice labels into option IDs', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $statusField = CustomField::query()
        ->where('tenant_id', $user->currentTeam->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();

    $doneId = $statusField->options->firstWhere('name', 'Done')->id;

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($user, 'task', ['status' => 'Done']);

    expect($result->error)->toBeNull()
        ->and($result->cleanFields)->toBe(['status' => $doneId]);
});

it('translates multi-choice labels into an array of option IDs', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->getKey();

    $field = CustomField::query()
        ->create([
            'code' => 'test_multi',
            'name' => 'Test Multi',
            'type' => 'multi-select',
            'entity_type' => 'task',
            'tenant_id' => $teamId,
            'active' => true,
            'system_defined' => false,
        ]);
    $optA = $field->options()->create(['name' => 'Alpha', 'tenant_id' => $teamId, 'sort_order' => 1]);
    $optB = $field->options()->create(['name' => 'Beta', 'tenant_id' => $teamId, 'sort_order' => 2]);

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($user, 'task', ['test_multi' => ['Alpha', 'Beta']]);

    expect($result->error)->toBeNull()
        ->and($result->cleanFields['test_multi'])
        ->toBe([$optA->id, $optB->id]);
});

it('returns a descriptive error for an unknown field code', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($user, 'task', ['does_not_exist' => 'value']);

    expect($result->error)
        ->toContain('does_not_exist');
});

it('returns a descriptive error for an unknown single-choice label', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($user, 'task', ['status' => 'Bananas']);

    expect($result->error)
        ->toContain('status')
        ->toContain('Bananas');
});

it('returns an empty clean payload when input is null or empty array', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $validator = resolve(CustomFieldsRequestValidator::class);

    expect($validator->validate($user, 'task', null)->cleanFields)->toBe([])
        ->and($validator->validate($user, 'task', [])->cleanFields)->toBe([]);
});

it('enforces required custom fields on create but not on update', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    CustomField::query()
        ->where('tenant_id', $user->currentTeam->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->update(['validation_rules' => json_encode(['required' => true])]);

    $validator = resolve(CustomFieldsRequestValidator::class);

    expect($validator->validate($user, 'task', null, isUpdate: false)->error)->toContain('status')
        ->and($validator->validate($user, 'task', ['description' => 'x'], isUpdate: false)->error)->toContain('status')
        ->and($validator->validate($user, 'task', ['status' => 'Done'], isUpdate: false)->error)->toBeNull()
        ->and($validator->validate($user, 'task', null, isUpdate: true)->error)->toBeNull()
        ->and($validator->validate($user, 'task', ['description' => 'x'], isUpdate: true)->error)->toBeNull();
});

it('passes null through for a single-choice field so the value can be cleared', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($user, 'task', ['priority' => null]);

    expect($result->error)->toBeNull()
        ->and($result->cleanFields)->toBe(['priority' => null]);
});

it('passes null through for a multi-choice field so the value can be cleared', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $multi = CustomField::query()
        ->where('tenant_id', $user->currentTeam->getKey())
        ->where('entity_type', 'company')
        ->whereIn('type', ['multi-select', 'tags-input'])
        ->first();

    if (! $multi instanceof CustomField) {
        expect(true)->toBeTrue();

        return;
    }

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($user, 'company', [$multi->code => null]);

    expect($result->error)->toBeNull()
        ->and($result->cleanFields)->toBe([$multi->code => null]);
});

it('rejects clearing a required choice field with a truthful validation error', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    CustomField::query()
        ->where('tenant_id', $user->currentTeam->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'priority')
        ->firstOrFail()
        ->update(['validation_rules' => ['required' => true]]);

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($user, 'task', ['priority' => null]);

    expect($result->error)->toContain('custom_fields validation failed')
        ->and($result->error)->not->toContain('option label string');
});
