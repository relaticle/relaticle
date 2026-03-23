---
name: custom-fields-development
description: Adds dynamic custom fields to Eloquent models without migrations using Filament integration. Use when adding the UsesCustomFields trait to models, integrating custom fields in Filament forms/tables/infolists, configuring field types, working with field validation, or managing feature flags for conditional visibility, encryption, and multi-tenancy.
---

# Custom Fields Development

## When to Use This Skill

Use when:
- Adding custom fields capability to an Eloquent model
- Integrating custom fields into Filament resources (forms, tables, infolists)
- Configuring field types, validation, or visibility
- Working with feature flags (encryption, multi-tenancy, sections)
- Creating CSV importers/exporters with custom field support

## Quick Start

### 1. Add Trait to Model

```php
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;

class Contact extends Model implements HasCustomFields
{
    use UsesCustomFields;
}
```

### 2. Register Plugin in Panel

```php
use Relaticle\CustomFields\CustomFieldsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            CustomFieldsPlugin::make()
                ->authorize(fn () => auth()->user()->isAdmin()),
        ]);
}
```

### 3. Publish and Run Migrations

```bash
php artisan vendor:publish --tag=custom-fields-migrations
php artisan migrate
```

## Filament Integration

Use the `CustomFields` facade to generate form/table/infolist components.

### Form Schema

```php
use Relaticle\CustomFields\Facades\CustomFields;

public static function form(Form $form): Form
{
    return $form->schema([
        TextInput::make('name')->required(),
        // Add custom fields after regular fields
        CustomFields::form()->forSchema($form)->build(),
    ]);
}
```

**Builder methods:**
- `forSchema(Schema $schema)` - Auto-detect model from form/infolist
- `forModel(Model|string $model)` - Explicit model binding
- `only(['code1', 'code2'])` - Include only specific fields
- `except(['code1'])` - Exclude specific fields
- `withoutSections()` - Flatten fields without section grouping

### Table Columns and Filters

```php
use Relaticle\CustomFields\Facades\CustomFields;

public static function table(Table $table): Table
{
    $customFields = CustomFields::table()->forModel(Contact::class);

    return $table
        ->columns([
            TextColumn::make('name'),
            ...$customFields->columns(),
        ])
        ->filters([
            ...$customFields->filters(),
        ]);
}
```

### Infolist Entries

```php
use Relaticle\CustomFields\Facades\CustomFields;

public static function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        TextEntry::make('name'),
        CustomFields::infolist()->forSchema($infolist)->build(),
    ]);
}
```

### CSV Import/Export

```php
use Relaticle\CustomFields\Facades\CustomFields;

// In Importer class
public function getColumns(): array
{
    return [
        ImportColumn::make('name'),
        ...CustomFields::importer()->forModel(Contact::class)->columns()->toArray(),
    ];
}

// In Exporter class
public function getColumns(): array
{
    return [
        ExportColumn::make('name'),
        ...CustomFields::exporter()->forModel(Contact::class)->columns()->toArray(),
    ];
}
```

## Available Field Types

| Type | Key | Data Storage |
|------|-----|--------------|
| Text | `text` | text_value |
| Email | `email` | json_value |
| Phone | `phone` | json_value |
| Textarea | `textarea` | text_value |
| Rich Editor | `rich-editor` | text_value |
| Markdown | `markdown-editor` | text_value |
| Link | `link` | json_value |
| Number | `number` | integer_value |
| Currency | `currency` | float_value |
| Date | `date` | date_value |
| DateTime | `date-time` | datetime_value |
| Select | `select` | string_value |
| Multi-Select | `multi-select` | json_value |
| Checkbox | `checkbox` | boolean_value |
| Checkbox List | `checkbox-list` | json_value |
| Radio | `radio` | string_value |
| Toggle | `toggle` | boolean_value |
| Toggle Buttons | `toggle-buttons` | string_value |
| Tags Input | `tags-input` | json_value |
| Color Picker | `color-picker` | text_value |
| File Upload | `file-upload` | string_value |
| Record Select | `record` | json_value |

### Field Type Key Naming

**Convention:** Use `kebab-case` for all field type keys.

**For custom field types**, use a project prefix to avoid conflicts:

| Scenario | Pattern | Example |
|----------|---------|---------|
| New custom type | `{project}-{name}` | `acme-star-rating` |
| Extended built-in | `{project}-{original}` | `acme-rich-editor` |
| Replace built-in | Same key | `rich-editor` |

**Why prefix?**
- Avoids accidental override of built-in types
- Future-proof against new package versions
- Clear identification in database/UI
- Safe for multi-vendor environments

## Feature Flags

Configure in `config/custom-fields.php` using `FeatureConfigurator`:

```php
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureConfigurator;

'features' => FeatureConfigurator::configure()
    ->enable(
        CustomFieldsFeature::FIELD_CONDITIONAL_VISIBILITY,
        CustomFieldsFeature::FIELD_ENCRYPTION,
        CustomFieldsFeature::FIELD_OPTION_COLORS,
        CustomFieldsFeature::UI_TABLE_COLUMNS,
        CustomFieldsFeature::UI_TABLE_FILTERS,
        CustomFieldsFeature::SYSTEM_SECTIONS,
    )
    ->disable(
        CustomFieldsFeature::SYSTEM_MULTI_TENANCY,
    ),
```

**Feature Categories:**

| Feature | Purpose |
|---------|---------|
| `FIELD_CONDITIONAL_VISIBILITY` | Show/hide fields based on other field values |
| `FIELD_ENCRYPTION` | Encrypt sensitive field values |
| `FIELD_OPTION_COLORS` | Color badges for select/checkbox options |
| `FIELD_VALIDATION_RULES` | Enable validation rule configuration |
| `UI_TABLE_COLUMNS` | Show custom fields as table columns |
| `UI_TABLE_FILTERS` | Enable filtering by custom fields |
| `UI_TOGGLEABLE_COLUMNS` | Allow users to toggle column visibility |
| `UI_FIELD_WIDTH_CONTROL` | Control field width in forms |
| `SYSTEM_MANAGEMENT_INTERFACE` | Admin page for managing fields |
| `SYSTEM_SECTIONS` | Organize fields into sections |
| `SYSTEM_MULTI_TENANCY` | Tenant isolation for fields |

## Configuration

### Entity Discovery

```php
use Relaticle\CustomFields\EntitySystem\EntityConfigurator;

'entity_configuration' => EntityConfigurator::configure()
    ->discover(app_path('Models'))
    ->exclude(['User', 'Team'])
    ->cache(enabled: true, ttl: 3600),
```

### Field Type Control

```php
use Relaticle\CustomFields\FieldTypeSystem\FieldTypeConfigurator;

'field_type_configuration' => FieldTypeConfigurator::configure()
    ->enabled(['text', 'email', 'select', 'number'])
    ->disabled(['file-upload'])
    ->discover(true)
    ->cache(enabled: true),
```

### Custom Tenant Resolver

For multi-tenancy outside Filament panels:

```php
use Relaticle\CustomFields\CustomFields;

// In AppServiceProvider::boot()
CustomFields::resolveTenantUsing(fn () => auth()->user()?->team_id);
```

## Programmatic Field Access

```php
// Get all custom fields for a model
$fields = $contact->customFields()->get();

// Get a specific field value
$value = $contact->getCustomFieldValue($customField);

// Save a field value
$contact->saveCustomFieldValue($customField, 'new value');

// Save multiple field values
$contact->saveCustomFields([
    'industry' => 'Technology',
    'company_size' => 'Enterprise',
]);
```

## Database Schema

Four tables are created:

- `custom_field_sections` - Optional grouping of fields
- `custom_fields` - Field definitions (name, code, type, validation)
- `custom_field_options` - Choice options for select/checkbox fields
- `custom_field_values` - Polymorphic storage of field values

Values are stored in type-specific columns: `string_value`, `text_value`, `integer_value`, `float_value`, `boolean_value`, `date_value`, `datetime_value`, `json_value`.

## Custom Models

Override default models for custom behavior:

```php
use Relaticle\CustomFields\CustomFields;

// In AppServiceProvider::boot()
CustomFields::useCustomFieldModel(MyCustomField::class);
CustomFields::useValueModel(MyCustomFieldValue::class);
CustomFields::useOptionModel(MyCustomFieldOption::class);
CustomFields::useSectionModel(MyCustomFieldSection::class);
```

## Register Custom Field Types

```php
use Relaticle\CustomFields\CustomFieldsPlugin;

CustomFieldsPlugin::make()
    ->registerFieldTypes([
        MyCustomFieldType::class,
    ])
```

Custom field types must extend `Relaticle\CustomFields\FieldTypeSystem\BaseFieldType`.