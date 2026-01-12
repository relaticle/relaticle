<?php

declare(strict_types=1);

use Relaticle\CustomFields\EntitySystem\EntityConfigurator;
use Relaticle\CustomFields\EntitySystem\EntityModel;
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
    | lookupIdentifier: Specifies the unique custom field used for matching
    | records during imports (e.g., email for People, domain for Company).
    |
    */
    'entity_configuration' => EntityConfigurator::configure()
        ->discover(app_path('Models'))
        ->models([
            EntityModel::configure(
                modelClass: App\Models\People::class,
                lookupIdentifier: ['field_code' => 'emails', 'value_column' => 'json_value'],
            ),
            EntityModel::configure(
                modelClass: App\Models\Company::class,
                lookupIdentifier: ['field_code' => 'domains', 'value_column' => 'json_value'],
            ),
            EntityModel::for(App\Models\Opportunity::class),
            EntityModel::for(App\Models\Task::class),
            EntityModel::for(App\Models\Note::class),
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
            CustomFieldsFeature::SYSTEM_MANAGEMENT_INTERFACE,
            CustomFieldsFeature::SYSTEM_MULTI_TENANCY,
        )->disable(
            CustomFieldsFeature::FIELD_CONDITIONAL_VISIBILITY,
            CustomFieldsFeature::FIELD_VALIDATION_RULES,
            CustomFieldsFeature::UI_FIELD_WIDTH_CONTROL,
            CustomFieldsFeature::SYSTEM_SECTIONS_ENABLED,
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
        'navigation_group' => true,
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
        'table_names' => [
            'custom_field_sections' => 'custom_field_sections',
            'custom_fields' => 'custom_fields',
            'custom_field_values' => 'custom_field_values',
            'custom_field_options' => 'custom_field_options',
        ],
        'column_names' => [
            'tenant_foreign_key' => 'tenant_id',
        ],
    ],
];
