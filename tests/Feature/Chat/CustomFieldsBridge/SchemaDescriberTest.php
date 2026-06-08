<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\CustomField;
use App\Models\User;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\Tools\CustomFieldsSchemaDescriber;

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

it('describes the system-seeded task custom fields with type hints', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $description = resolve(CustomFieldsSchemaDescriber::class)
        ->describe($user->currentTeam, 'task');

    expect($description)
        ->toContain('Available custom fields')
        ->toContain('due_date')
        ->toContain('date-time')
        ->toContain('ISO 8601')
        ->toContain('status')
        ->toContain('single-choice')
        ->toContain('"To do"')
        ->toContain('"In progress"')
        ->toContain('"Done"')
        ->toContain('priority')
        ->toContain('description');
});

it('returns a stable, sorted listing so the description is cache-friendly', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $describer = resolve(CustomFieldsSchemaDescriber::class);

    $first = $describer->describe($user->currentTeam, 'task');
    $second = $describer->describe($user->currentTeam, 'task');

    expect($first)->toBe($second);
});

it('returns an empty marker when the entity has no custom fields for the tenant', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    CustomField::query()
        ->where('tenant_id', $user->currentTeam->getKey())
        ->where('entity_type', 'task')
        ->delete();

    $description = resolve(CustomFieldsSchemaDescriber::class)
        ->describe($user->currentTeam, 'task');

    expect($description)->toBe('No custom fields are defined for this entity type.');
});
