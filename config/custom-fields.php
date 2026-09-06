<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\Task;
use Relaticle\CustomFields\EntitySystem\EntityConfigurator;
use Relaticle\CustomFields\EntitySystem\EntityModel;
use Relaticle\CustomFields\Enums\AvatarShape;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureConfigurator;
use Relaticle\CustomFields\FieldTypeSystem\FieldTypeConfigurator;

return [
    /*
    |--------------------------------------------------------------------------
    | Entity Configuration
    |--------------------------------------------------------------------------
    |
    | Configure entities (models that can have custom fields) using the
    | clean, type-safe fluent builder interface.
    |
    */
    'entity_configuration' => EntityConfigurator::configure()
        ->discover(app_path('Models'))
        ->models([
            EntityModel::configure(
                modelClass: People::class,
                labelSingular: 'Person',
                labelPlural: 'People',
                primaryAttribute: 'name',
                resourceClass: PeopleResource::class,
                avatarConfiguration: EntityModel::avatar(attribute: 'avatar'),
            ),
            EntityModel::configure(
                modelClass: Company::class,
                primaryAttribute: 'name',
                resourceClass: CompanyResource::class,
                avatarConfiguration: EntityModel::avatar(attribute: 'logo', shape: AvatarShape::Square),
            ),
            EntityModel::configure(
                modelClass: Note::class,
                primaryAttribute: 'title',
                resourceClass: NoteResource::class,
                recordPage: null,
            ),
            EntityModel::configure(
                modelClass: Task::class,
                primaryAttribute: 'title',
                resourceClass: TaskResource::class,
                recordPage: null,
            ),
        ])
        ->cache(),

    /*
    |--------------------------------------------------------------------------
    | Advanced Field Type Configuration
    |--------------------------------------------------------------------------
    |
    | Configure field types using the powerful fluent builder API.
    | This provides advanced control over validation, security, and behavior.
    |
    */
    'field_type_configuration' => FieldTypeConfigurator::configure()
        // Control which field types are available globally
        ->enabled([]) // Empty = all enabled, or specify: ['text', 'email', 'select']
        ->disabled(['file-upload']) // Disable specific field types
        ->discover(true)
        ->cache(enabled: true, ttl: 3600),

    /*
    |--------------------------------------------------------------------------
    | Features Configuration
    |--------------------------------------------------------------------------
    |
    | Configure package features using the type-safe enum-based configurator.
    | This consolidates all feature settings into a single, organized system.
    |
    */
    'features' => FeatureConfigurator::configure()
        ->enable(
            CustomFieldsFeature::FIELD_ENCRYPTION,
            CustomFieldsFeature::FIELD_OPTION_COLORS,
            CustomFieldsFeature::FIELD_MULTI_VALUE,
            CustomFieldsFeature::FIELD_UNIQUE_VALUE,
            CustomFieldsFeature::FIELD_CODE_AUTO_GENERATE,
            CustomFieldsFeature::UI_TABLE_COLUMNS,
            CustomFieldsFeature::UI_TOGGLEABLE_COLUMNS,
            CustomFieldsFeature::UI_TABLE_FILTERS,
            CustomFieldsFeature::SYSTEM_MULTI_TENANCY,

            // Creates the two relationship tables and lets the upgrade command run. Record
            // fields store their targets as links from 4.0 on, so this is not optional here.
            CustomFieldsFeature::SYSTEM_RELATIONSHIPS,
        )->disable(
            // Hide the package's management page from the sidebar; reachable via the
            // tenant dropdown's "Custom Fields" entry. The page route is still registered.
            CustomFieldsFeature::SYSTEM_MANAGEMENT_INTERFACE,
            CustomFieldsFeature::FIELD_CONDITIONAL_VISIBILITY,
            CustomFieldsFeature::FIELD_VALIDATION_RULES,
            CustomFieldsFeature::UI_FIELD_WIDTH_CONTROL,
            CustomFieldsFeature::SYSTEM_SECTIONS,

            // Off in 3.x because this block did not name them, and a flag it does not name
            // now takes the package default instead. Naming them keeps the field editor and
            // the record pages exactly as they are; each is a product call, not a bump.
            CustomFieldsFeature::FIELD_DESCRIPTION,
            CustomFieldsFeature::FIELD_DESCRIPTION_POSITION,
            CustomFieldsFeature::SECTION_CONDITIONAL_VISIBILITY,
            CustomFieldsFeature::UI_SECTION_WIDTH_CONTROL,
            CustomFieldsFeature::MODEL_ATTRIBUTE_CONDITIONS,
            CustomFieldsFeature::UI_TOGGLEABLE_COLUMNS_HIDDEN_DEFAULT,
        ),

    /*
    |--------------------------------------------------------------------------
    | Resource Configuration
    |--------------------------------------------------------------------------
    |
    | Customize the behavior of entity resources in Filament.
    |
    */
    'resource' => [
        'table' => [
            'columns' => true,
            'columns_toggleable' => true,
            'filters' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Management Interface
    |--------------------------------------------------------------------------
    |
    | Configure the Custom Fields management interface in Filament.
    |
    */
    'management' => [
        'enabled' => true,
        'slug' => 'custom-fields',
        'navigation_sort' => 100,
        'navigation_group_enabled' => true,
        'cluster' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | Enable multi-tenancy support with automatic tenant isolation.
    |
    */
    'tenant_aware' => true,

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Configure database table names and migration paths.
    |
    */
    'database' => [
        'migrations_path' => database_path('custom-fields'),

        // Every table this application owns is keyed by ULID, and the tables added in 4.0
        // read this instead of being hand-edited after publishing.
        'key_type' => 'ulid',

        'table_names' => [
            'custom_field_sections' => 'custom_field_sections',
            'custom_fields' => 'custom_fields',
            'custom_field_values' => 'custom_field_values',
            'custom_field_options' => 'custom_field_options',
            'custom_field_relationships' => 'custom_field_relationships',
            'custom_field_links' => 'custom_field_links',
        ],
        'column_names' => [
            'tenant_foreign_key' => 'tenant_id',
        ],
    ],
];
