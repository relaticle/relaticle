<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Workspace;
use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\Services\Relationships\CreateRelationshipDefinition;
use Relaticle\CustomFields\Services\TenantContextService;

/**
 * Link fields as the product creates them: a field row plus the relationship definition
 * that makes it a slot. Everything downstream of a link field (chat, API, MCP, import,
 * the timeline) needs one, so the shape lives here rather than in each test.
 */
final class RecordFieldFixture
{
    /**
     * A one-way `record` field on $entityType pointing at $targetEntityType.
     */
    public static function record(
        Workspace $workspace,
        string $entityType,
        string $targetEntityType,
        string $code,
        RelationshipCardinality $cardinality = RelationshipCardinality::ManyToMany,
        ?string $name = null,
    ): CustomField {
        $field = self::field($workspace, $entityType, $code, $name ?? ucfirst($code), CustomFieldType::RECORD->value);

        self::definition(new RelationshipDefinitionData(
            code: $entityType.'_'.$code,
            fromEntityType: $entityType,
            toEntityType: $targetEntityType,
            cardinality: $cardinality,
            fromField: new FieldSlotData(name: $field->name, fieldId: $field->getKey()),
        ));

        return $field->refresh();
    }

    /**
     * A paired `relationship` field: the near slot on $entityType, the far slot on
     * $targetEntityType, both reading the same edges.
     *
     * @return array{0: CustomField, 1: CustomField}
     */
    public static function paired(
        Workspace $workspace,
        string $entityType,
        string $targetEntityType,
        string $code,
        string $farCode,
        RelationshipCardinality $cardinality = RelationshipCardinality::ManyToMany,
    ): array {
        $near = self::field($workspace, $entityType, $code, ucfirst($code), CustomFieldType::RELATIONSHIP->value);
        $far = self::field($workspace, $targetEntityType, $farCode, ucfirst($farCode), CustomFieldType::RELATIONSHIP->value);

        self::definition(new RelationshipDefinitionData(
            code: $entityType.'_'.$code,
            fromEntityType: $entityType,
            toEntityType: $targetEntityType,
            cardinality: $cardinality,
            fromField: new FieldSlotData(name: $near->name, fieldId: $near->getKey()),
            toField: new FieldSlotData(name: $far->name, fieldId: $far->getKey()),
        ));

        return [$near->refresh(), $far->refresh()];
    }

    /**
     * Point an already-created record field at an entity. For tests that build the field
     * row themselves and only need the definition that replaced `lookup_type`.
     */
    public static function pointAt(
        CustomField $field,
        string $targetEntityType,
        RelationshipCardinality $cardinality = RelationshipCardinality::ManyToMany,
    ): CustomField {
        // The definition takes its tenant from ambient context, and the link rows take
        // theirs from the definition, so a definition built without it writes links no
        // tenant-scoped read can see.
        TenantContextService::setTenantId($field->tenant_id);

        self::definition(new RelationshipDefinitionData(
            code: $field->entity_type.'_'.$field->code,
            fromEntityType: (string) $field->entity_type,
            toEntityType: $targetEntityType,
            cardinality: $cardinality,
            fromField: new FieldSlotData(name: $field->name, fieldId: $field->getKey()),
        ));

        return $field->refresh();
    }

    private static function definition(RelationshipDefinitionData $data): CustomFieldRelationship
    {
        return resolve(CreateRelationshipDefinition::class)->execute($data);
    }

    private static function field(Workspace $workspace, string $entityType, string $code, string $name, string $type): CustomField
    {
        $section = CustomFieldSection::query()->firstOrCreate([
            'tenant_id' => $workspace->getKey(),
            'entity_type' => $entityType,
            'code' => 'general',
        ], [
            'name' => 'General',
            'type' => 'section',
            'sort_order' => 0,
            'active' => true,
        ]);

        TenantContextService::setTenantId($workspace->getKey());

        return CustomField::query()->create([
            'tenant_id' => $workspace->getKey(),
            'custom_field_section_id' => $section->getKey(),
            'entity_type' => $entityType,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'sort_order' => 1,
            'active' => true,
            'validation_rules' => [],
        ]);
    }
}
