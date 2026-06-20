<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\User;
use App\Support\ActivityLog\MergedActivityRenderer;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $section = CustomFieldSection::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'company',
        'code' => 'general',
        'name' => 'General',
        'type' => 'section',
        'sort_order' => 0,
        'active' => true,
    ]);

    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'company',
        'code' => 'lead_source',
        'name' => 'Lead source',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $this->company = Company::factory()->for($this->team)->create();
    Activity::withoutGlobalScopes()->delete();
});

/**
 * Insert an activity row directly so each test controls the batch_uuid it groups
 * on — the per-request stamping itself is covered separately and end-to-end in the
 * browser flow.
 *
 * @param  array<string, mixed>  $attributeChanges
 * @param  array<string, mixed>  $properties
 */
function seedActivityRow(Company $company, string $event, ?string $batchUuid, array $attributeChanges = [], array $properties = []): void
{
    Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => $event,
        'event' => $event,
        'subject_type' => $company->getMorphClass(),
        'subject_id' => $company->getKey(),
        'attribute_changes' => $attributeChanges,
        'properties' => $properties,
        'batch_uuid' => $batchUuid,
        'team_id' => $company->team_id,
    ]);
}

it('stamps a batch_uuid on every activity row written during one save', function (): void {
    $this->company->update(['name' => 'Renamed Co']);
    $this->company->saveCustomFields(['lead_source' => 'referral']);

    $batches = Activity::withoutGlobalScopes()->pluck('batch_uuid');

    expect($batches)->toHaveCount(2)
        ->and($batches->filter())->toHaveCount(2);
});

it('collapses a save\'s native and custom-field rows into one merged entry', function (): void {
    $batch = '11111111-1111-1111-1111-111111111111';

    seedActivityRow($this->company, 'updated', $batch, ['attributes' => ['name' => 'Renamed Co'], 'old' => ['name' => 'Old Co']]);
    seedActivityRow($this->company, 'custom_field_changes', $batch, [], [
        'custom_field_changes' => [[
            'code' => 'lead_source',
            'label' => 'Lead source',
            'old' => ['value' => null, 'label' => '—'],
            'new' => ['value' => 'referral', 'label' => 'referral'],
        ]],
    ]);

    $entries = $this->company->timeline()->get();

    expect($entries)->toHaveCount(1);

    $entry = $entries->first();

    expect($entry->renderer)->toBe('merged-activity')
        ->and($entry->properties)->toHaveKeys(['attributes', 'custom_field_changes'])
        ->and($entry->properties['attributes']['name'])->toBe('Renamed Co')
        ->and($entry->properties['custom_field_changes'][0]['code'])->toBe('lead_source');
})->mutates(MergedActivityRenderer::class);

it('keeps rows from different batches as separate entries', function (): void {
    seedActivityRow($this->company, 'custom_field_changes', '22222222-2222-2222-2222-222222222222', [], [
        'custom_field_changes' => [['code' => 'lead_source', 'label' => 'Lead source', 'old' => ['label' => '—'], 'new' => ['label' => 'referral']]],
    ]);
    seedActivityRow($this->company, 'custom_field_changes', '33333333-3333-3333-3333-333333333333', [], [
        'custom_field_changes' => [['code' => 'lead_source', 'label' => 'Lead source', 'old' => ['label' => 'referral'], 'new' => ['label' => 'linkedin']]],
    ]);

    expect($this->company->timeline()->get())->toHaveCount(2);
});

it('never merges an un-batched legacy row', function (): void {
    // Rows predating the batch hook carry a null batch_uuid; each stays on its own.
    seedActivityRow($this->company, 'updated', null, ['attributes' => ['name' => 'Legacy'], 'old' => ['name' => 'Old']]);
    seedActivityRow($this->company, 'updated', null, ['attributes' => ['name' => 'Legacy 2'], 'old' => ['name' => 'Legacy']]);

    $entries = $this->company->timeline()->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->first()->renderer)->toBe('merged-activity');
});
