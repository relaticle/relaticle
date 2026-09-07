<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldLink;
use App\Models\CustomFieldRelationship;
use App\Models\CustomFieldSection;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\Relationships\CreateRelationshipDefinition;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(CustomFieldRelationship::class, CustomFieldLink::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());

    $section = CustomFieldSection::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'people',
        'code' => 'general',
        'name' => 'General',
        'type' => 'section',
        'sort_order' => 0,
        'active' => true,
    ]);

    $this->field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'people',
        'code' => 'vendors',
        'name' => 'Vendors',
        'type' => 'record',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $this->definition = resolve(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'people_vendors',
        fromEntityType: 'people',
        toEntityType: 'company',
        cardinality: RelationshipCardinality::ManyToMany,
        fromField: new FieldSlotData(name: 'Vendors', fieldId: $this->field->getKey()),
    ));
});

it('keys the relationship definition and its links by ulid', function (): void {
    $person = People::factory()->for($this->team)->create();
    $company = Company::factory()->for($this->team)->create();

    $person->update(['custom_fields' => ['vendors' => [$company->getKey()]]]);

    $link = CustomFieldLink::query()->sole();

    expect($this->definition)->toBeInstanceOf(CustomFieldRelationship::class)
        ->and($this->definition->getKey())->toHaveLength(26)
        ->and($this->definition->tenant_id)->toBe($this->team->getKey())
        ->and($this->definition->from_field_id)->toBe($this->field->getKey())
        ->and($link->getKey())->toHaveLength(26)
        ->and($link->relationship_id)->toBe($this->definition->getKey())
        ->and($link->tenant_id)->toBe($this->team->getKey())
        ->and($link->from_entity_type)->toBe('people')
        ->and($link->from_entity_id)->toBe($person->getKey())
        ->and($link->to_entity_type)->toBe('company')
        ->and($link->to_entity_id)->toBe($company->getKey())
        ->and($link->created_by_id)->toBe($this->user->getKey());
});

it('writes record targets to links instead of values and reads them back in order', function (): void {
    $person = People::factory()->for($this->team)->create();
    $first = Company::factory()->for($this->team)->create();
    $second = Company::factory()->for($this->team)->create();

    $person->update(['custom_fields' => ['vendors' => [$second->getKey(), $first->getKey()]]]);

    expect(CustomFieldValue::query()->where('custom_field_id', $this->field->getKey())->count())->toBe(0)
        ->and(CustomFieldLink::query()->whereNull('active_until')->count())->toBe(2)
        ->and(People::query()->whereKey($person->getKey())->sole()->getCustomFieldValue($this->field))
        ->toBe([$second->getKey(), $first->getKey()]);
});

it('closes the edge a later payload drops and keeps it out of the read', function (): void {
    $person = People::factory()->for($this->team)->create();
    $kept = Company::factory()->for($this->team)->create();
    $dropped = Company::factory()->for($this->team)->create();

    $person->update(['custom_fields' => ['vendors' => [$kept->getKey(), $dropped->getKey()]]]);
    $person->update(['custom_fields' => ['vendors' => [$kept->getKey()]]]);

    $closed = CustomFieldLink::query()->whereNotNull('active_until')->sole();

    expect($closed->to_entity_id)->toBe($dropped->getKey())
        ->and(CustomFieldLink::query()->whereNull('active_until')->count())->toBe(1)
        ->and(People::query()->whereKey($person->getKey())->sole()->getCustomFieldValue($this->field))
        ->toBe([$kept->getKey()]);
});

it('clears every edge when the payload is empty', function (): void {
    $person = People::factory()->for($this->team)->create();
    $company = Company::factory()->for($this->team)->create();

    $person->update(['custom_fields' => ['vendors' => [$company->getKey()]]]);
    $person->update(['custom_fields' => ['vendors' => []]]);

    expect(CustomFieldLink::query()->whereNull('active_until')->count())->toBe(0)
        ->and(CustomFieldLink::query()->whereNotNull('active_until')->count())->toBe(1)
        ->and(People::query()->whereKey($person->getKey())->sole()->getCustomFieldValue($this->field))
        ->toBe([]);
});
