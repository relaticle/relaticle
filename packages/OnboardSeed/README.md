# OnboardSeed

## Overview

OnboardSeed provides a structured way to seed demo data for new teams. It uses YAML fixtures to define entities and
their relationships, with a clean API for generating consistent demo data across multiple environments.

## Features

- Declarative YAML-based fixture definitions
- Registry pattern for tracking entity relationships
- All relationships defined directly in YAML fixtures
- Consistent handling of custom fields
- Extendable architecture for adding new entity types

## Fixture Format

All fixtures follow a standardized format:

```yaml
# Required attributes for the entity
name: "Entity Name"              # Required for most entities
title: "Entity Title"            # For tasks/notes

# References to other entities (singular form)
company: company_key             # Reference to another entity

# For notes, use noteable_type and noteable_key
# Important: noteable_type should be SINGULAR (company, person)
noteable_type: company           # The singular entity type 
noteable_key: company_fixture_key # The key of the referenced entity

# Many-to-many relationships
assigned_people: # Use plural form as property name
    - person_key1                  # List related entity keys
    - person_key2

# Custom fields
custom_fields: # All custom field values under this key
    field_code: value              # Simple value
    another_field: [ value1, value2 ]  # Array value

    # Dynamic value using simple date expression
    date_field: '{{ +5d }}'
```

### Relationship Types in YAML

The package supports defining different types of relationships directly in YAML fixtures:

1. **Belongs-To Relationships** - References to parent entities:
   ```yaml
   # In people/tim.yaml
   company: apple  # Person belongs to the Apple company
   ```

2. **Many-to-Many Relationships** - Lists of related entities:
   ```yaml
   # In tasks/meeting.yaml
   assigned_people:
     - tim
     - dylan
   ```

3. **Polymorphic Relationships** - Used for notes and other polymorphic relations:
   ```yaml
   # In notes/meeting_note.yaml
   noteable_type: company  # The entity type (singular)
   noteable_key: apple     # The entity key
   ```

### Naming Conventions

- **Fixture Files**: `lowercase_with_underscores.yaml`
- **Entity Keys**: Same as the filename without extension
- **Entity References**: Always use the singular form of the entity type
    - Example: In fixtures, use `company: figma` (not `companies: figma`)
    - For notes, use `noteable_type: company` (not `noteable_type: companies`)
- **Registry Keys**: Always use the plural form of the entity type
    - Internally, entities are stored in the registry with plural keys
    - Example: `companies`, `people`, `opportunities`
- **Relationship Lists**: Use plural form of the entity type
    - Example: `assigned_people`, `related_tasks`

## Adding New Fixture Data

### 1. Create a YAML File

Create a new YAML file in the appropriate directory:

```yaml
# resources/fixtures/companies/new_company.yaml
name: New Company Name
custom_fields:
    domain_name: https://example.com
    icp: true
    linkedin: https://www.linkedin.com/company/example
```

For tasks with people relationships:

```yaml
# resources/fixtures/tasks/meeting.yaml
title: Team Meeting
assigned_people:
    - person_key1
    - person_key2
custom_fields:
    description: Weekly team meeting
    due_date: '{{ +2d }}'
    status: To do
    priority: High
```

For notes that reference other entities:

```yaml
# resources/fixtures/notes/company_note.yaml
title: Meeting Notes
noteable_type: company          # Singular form
noteable_key: new_company       # The key of the company
custom_fields:
    body: <p>Important meeting notes about this company.</p>
```

### 2. Handling Relationships in Seeders

Each model seeder has handlers for its specific relationship types:

```php
// In TaskSeeder.php - handling people assignments
if (isset($data['assigned_people']) && is_array($data['assigned_people'])) {
    foreach ($data['assigned_people'] as $personKey) {
        $person = FixtureRegistry::get('people', $personKey);
        $task->people()->attach($person->id);
    }
}

// In NoteSeeder.php - handling polymorphic relationships
$noteableType = $data['noteable_type'] ?? null;
$noteableKey = $data['noteable_key'] ?? null;
$registryKey = $this->getPluralEntityType($noteableType);
$noteable = FixtureRegistry::get($registryKey, $noteableKey);
```

## Adding a New Entity Type

### 1. Create Fixture Directory

```bash
mkdir -p resources/fixtures/new_entity_type
```

### 2. Create Fixtures

Create YAML files for your new entity type:

```yaml
# resources/fixtures/new_entity_type/example.yaml
name: Example
custom_fields:
    field1: value1
    field2: value2
```

### 3. Create a Seeder

Create a new seeder class that extends BaseModelSeeder:

```php
<?php

namespace Relaticle\OnboardSeed\ModelSeeders;

use App\Models\NewEntityType;
use Relaticle\OnboardSeed\Support\BaseModelSeeder;

final class NewEntityTypeSeeder extends BaseModelSeeder
{
    protected string $modelClass = NewEntityType::class;
    protected string $entityType = 'new_entity_types'; // Plural form
    
    protected array $fieldCodes = [
        'field1',
        'field2',
    ];
    
    protected function createEntitiesFromFixtures(Team $team, User $user, array $context = []): array
    {
        $fixtures = $this->loadEntityFixtures();
        $entities = [];
        
        foreach ($fixtures as $key => $data) {
            $entity = $this->createEntityFromFixture($team, $user, $key, $data);
            $entities[$key] = $entity;
        }
        
        return [
            'new_entity_types' => $entities, // Plural form
        ];
    }
    
    private function createEntityFromFixture(Team $team, User $user, string $key, array $data): NewEntityType
    {
        $attributes = [
            'name' => $data['name'],
            // other attributes
        ];
        
        $customFields = $data['custom_fields'] ?? [];
        
        return $this->registerEntityFromFixture($key, $attributes, $customFields, $team, $user);
    }
}
```

### 4. Register the Seeder

Add your seeder to the sequence in `OnboardSeedManager`:

```php
private array $entitySeederSequence = [
    CompanySeeder::class,
    // ...
    NewEntityTypeSeeder::class,
];
```

### 5. Update Type Mapping for Notes (If Needed)

If the new entity type can have notes attached, update the `$entityTypeMap` in the NoteSeeder:

```php
private array $entityTypeMap = [
    // Existing mappings...
    'new_entity_type' => 'new_entity_types', // Singular to plural mapping
];
```

## Template Expressions

You can use dynamic date values in fixtures using simple expressions:

```yaml
# Simple date keywords
due_date: '{{ now }}'           # Current datetime
start_date: '{{ today }}'       # Today's date
end_date: '{{ tomorrow }}'      # Tomorrow's date
last_contact: '{{ yesterday }}' # Yesterday's date
meeting_date: '{{ nextWeek }}'  # One week from today
previous_date: '{{ lastWeek }}' # One week before today
payment_date: '{{ nextMonth }}' # One month from today
last_payment: '{{ lastMonth }}' # One month before today

# Relative dates with simple syntax
followup_date: '{{ +7d }}'      # 7 days from now (+Nd for N days)
review_date: '{{ +2w }}'        # 2 weeks from now (+Nw for N weeks)
renewal_date: '{{ +3m }}'       # 3 months from now (+Nm for N months)
contract_date: '{{ +1y }}'      # 1 year from now (+Ny for N years)
deadline_date: '{{ +5b }}'      # 5 business days from now (+Nb for N business days)
```

For formatting dates, you can handle that in your model's accessor/mutator or in the seeder class.

## Best Practices

1. **Keep fixtures small and focused** - One entity per file
2. **Use descriptive keys** - Keys should describe the entity's role
3. **Organize fixtures logically** - Group related fixtures together
4. **Define all relationships in YAML** - Avoid defining relationships in PHP code
5. **Use references consistently** - Always reference by fixture key
6. **Remember singular vs plural forms** - Use singular in fixture references, plural in entity types 
