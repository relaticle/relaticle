# Custom Fields 4.0, Plan 1: Relationship Substrate

> **For agentic workers:** REQUIRED SUB-SKILL: Use sdd-lean (this project's standard) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move record links from jsonb EAV storage onto a typed edge ledger with relationship definitions, keeping the public payload contract unchanged.

**Architecture:** Two new tables in the relaticle/custom-fields package. `custom_field_relationships` holds definitions (vocabulary + 0-2 field slots). `custom_field_links` is a temporal edge ledger (actor, active_from/active_until). The `UsesCustomFields` write path forks record-type fields to a diff-based LinkWriter. Read paths keep returning ordered id arrays so every consumer survives.

**Tech Stack:** PHP 8.3+, Laravel 12 via testbench, Filament 5, Pest 4 (parallel), larastan, pint, rector, 100% type coverage.

**Spec:** docs/superpowers/specs/2026-08-31-custom-fields-4x-relationships-design.md (in relaticle/relaticle workspace; this plan implements spec sections 1 and 2)

**Repo:** work happens in a NEW worktree of ~/Herd/custom-fields on branch `4.x` (Task 1 creates it). Never work on the existing checkout's branch.

## Global Constraints

- PHP `^8.3`, `filament/filament ^5.0` (composer.json as-is).
- Quality gate per task before commit: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run` (apply if it suggests), `vendor/bin/phpstan analyse`, `vendor/bin/pest --type-coverage --min=100.0`, targeted `vendor/bin/pest --filter=<TestName>`.
- All new classes `declare(strict_types=1)`, final where package convention allows, fully typed (type coverage is a hard gate).
- Never write timestamps from the DB clock. Pass PHP `now()` explicitly. No `useCurrent()`.
- Commit style: conventional, lowercase, subject < 72 chars, present tense. No AI attribution ever.
- Table/column names always via `config('custom-fields.database.table_names...')` and the config column map, matching existing models.
- Tenant scoping: new models use `#[ScopedBy([TenantScope::class])]` exactly like `CustomFieldValue`. TenantScope only FILTERS reads; it never fills tenant_id. Every writer in this plan (definition create, slot fields, link inserts) must stamp tenant_id itself: resolve like `saveCustomFieldValue` does for definitions/fields, copy `definition->tenant_id` onto links.
- Model access: every reference to the two new models goes through the swap registry (`CustomFields::newRelationshipModel()` / `newLinkModel()`), never the concrete class, mirroring `newCustomFieldModel()`. Hosts (Relaticle) subclass with HasUlids + their own scopes.
- New migrations are publishable and up-only (no `down()`); ULID/UUID hosts publish and swap key types exactly as they do for the existing tables. `custom_field_links.relationship_id` must match the published key type of the definitions table.
- New user-facing strings go through `__('custom-fields::custom-fields...')` lang keys (add to `resources/lang/en/custom-fields.php`).

## Spec refinement (approved deviation, recorded here)

Spec 1.2 says cardinality is enforced with per-end partial unique indexes. That is not implementable statically: which end is constrained varies per definition row, and per-definition indexes would be dynamic DDL, which the spec itself forbids. Plan 1 therefore enforces:

1. A static partial unique index preventing duplicate active edges (all cardinalities).
2. One-end exclusivity inside the LinkWriter transaction via `lockForUpdate` on the DEFINITION ROW (`CustomFieldRelationship::whereKey($id)->lockForUpdate()->first()`), plus the validation layer for friendly errors. The definition row always exists, so this serializes writers per definition uniformly on every driver: no advisory-lock/driver switch, no phantom-insert gap when zero link rows exist. Skip the lock for `many_to_many` definitions, where the unique index alone suffices.

DB-trigger enforcement can be added later without schema change. The spec file carries the matching correction (2026-09-01 architecture review).

---

### Task 1: Worktree, branch, config keys, Cardinality enum

**Files:**
- Create: worktree `~/Herd/custom-fields-4x` on new branch `4.x` from `main`
- Modify: `config/custom-fields.php` (table_names map)
- Create: `src/Enums/RelationshipCardinality.php`
- Test: `tests/Feature/Relationships/CardinalityTest.php`

**Interfaces:**
- Produces: `RelationshipCardinality` enum: cases `OneToOne = 'one_to_one'`, `OneToMany = 'one_to_many'`, `ManyToOne = 'many_to_one'`, `ManyToMany = 'many_to_many'`; methods `fromSideIsSingle(): bool` (true for OneToOne, OneToMany is false... see code), `toSideIsSingle(): bool`, `label(): string`.
- Produces: config keys `custom-fields.database.table_names.custom_field_relationships` = `'custom_field_relationships'`, `...table_names.custom_field_links` = `'custom_field_links'`.

- [ ] **Step 1: Create worktree and branch**

```bash
cd ~/Herd/custom-fields
git fetch origin && git worktree add ../custom-fields-4x -b 4.x origin/main
cd ../custom-fields-4x && composer install
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Relationships/CardinalityTest.php`:

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Enums\RelationshipCardinality;

it('exposes single-or-multi per side', function (): void {
    expect(RelationshipCardinality::OneToOne->fromSideIsSingle())->toBeTrue()
        ->and(RelationshipCardinality::OneToOne->toSideIsSingle())->toBeTrue()
        ->and(RelationshipCardinality::OneToMany->fromSideIsSingle())->toBeFalse()
        ->and(RelationshipCardinality::OneToMany->toSideIsSingle())->toBeTrue()
        ->and(RelationshipCardinality::ManyToOne->fromSideIsSingle())->toBeTrue()
        ->and(RelationshipCardinality::ManyToOne->toSideIsSingle())->toBeFalse()
        ->and(RelationshipCardinality::ManyToMany->fromSideIsSingle())->toBeFalse()
        ->and(RelationshipCardinality::ManyToMany->toSideIsSingle())->toBeFalse();
});

it('registers the new table names in config', function (): void {
    expect(config('custom-fields.database.table_names.custom_field_relationships'))
        ->toBe('custom_field_relationships')
        ->and(config('custom-fields.database.table_names.custom_field_links'))
        ->toBe('custom_field_links');
});
```

Semantics note baked into the test: `fromSideIsSingle` answers "may a from-record hold at most one active link?" For `one_to_many` (one from-record links many to-records) the from side holds MANY links, so `fromSideIsSingle()` is false and `toSideIsSingle()` is true (each to-record belongs to one from-record). `many_to_one` is the mirror.

- [ ] **Step 3: Run to verify failure**: `vendor/bin/pest --filter=CardinalityTest` fails: class not found.

- [ ] **Step 4: Implement**

`src/Enums/RelationshipCardinality.php`:

```php
<?php

declare(strict_types=1);

namespace Relaticle\CustomFields\Enums;

enum RelationshipCardinality: string
{
    case OneToOne = 'one_to_one';
    case OneToMany = 'one_to_many';
    case ManyToOne = 'many_to_one';
    case ManyToMany = 'many_to_many';

    public function fromSideIsSingle(): bool
    {
        return in_array($this, [self::OneToOne, self::ManyToOne], true);
    }

    public function toSideIsSingle(): bool
    {
        return in_array($this, [self::OneToOne, self::OneToMany], true);
    }

    public function label(): string
    {
        return __('custom-fields::custom-fields.relationships.cardinality.'.$this->value);
    }
}
```

Add to `config/custom-fields.php` inside `'table_names'`:

```php
'custom_field_relationships' => 'custom_field_relationships',
'custom_field_links' => 'custom_field_links',
```

Add to `resources/lang/en/custom-fields.php` (top-level key `relationships`):

```php
'relationships' => [
    'cardinality' => [
        'one_to_one' => 'One to one',
        'one_to_many' => 'One to many',
        'many_to_one' => 'Many to one',
        'many_to_many' => 'Many to many',
    ],
],
```

- [ ] **Step 5: Run to verify pass, run gates, commit**

```bash
vendor/bin/pest --filter=CardinalityTest
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse && vendor/bin/pest --type-coverage --min=100.0
git add -A && git commit -m "feat(relationships): add cardinality enum and table name config"
```

---

### Task 2: Definitions table and model

**Files:**
- Create: `database/migrations/create_custom_field_relationships_table.php` (follow the publish pattern of `database/migrations/create_custom_fields_table.php`)
- Create: `src/Models/CustomFieldRelationship.php`
- Create: `database/factories/CustomFieldRelationshipFactory.php`
- Modify: `src/CustomFieldsServiceProvider.php` (register migration, same list as existing ones)
- Test: `tests/Feature/Relationships/RelationshipDefinitionModelTest.php`

**Interfaces:**
- Consumes: `RelationshipCardinality` (Task 1).
- Produces: model `CustomFieldRelationship` with properties `id, tenant_id, code, from_entity_type, to_entity_type, cardinality (cast RelationshipCardinality), from_field_id, to_field_id, is_symmetric (bool cast)`; relations `fromField(): BelongsTo`, `toField(): BelongsTo`; methods `directionFor(CustomField $field): string` returning `'from'|'to'` (throws `InvalidArgumentException` for an unrelated field), `isHeadless(): bool`.
- Produces: `CustomField::relationshipDefinition(): ?CustomFieldRelationship` helper on the CustomField model (query by from_field_id or to_field_id, cached per instance via `once()`).
- Produces: swap-registry entries on `CustomFields`: `useRelationshipModel(string $class)`, `newRelationshipModel()`, `relationshipModel()` mirroring the existing `useCustomFieldModel` trio. The helper and every later task resolve the model through the registry.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

it('persists a definition and reads direction per field', function (): void {
    $from = CustomField::factory()->create(['type' => 'record', 'entity_type' => 'post']);
    $to = CustomField::factory()->create(['type' => 'record', 'entity_type' => 'user']);

    $definition = CustomFieldRelationship::factory()->create([
        'code' => 'author_of',
        'from_entity_type' => 'post',
        'to_entity_type' => 'user',
        'cardinality' => RelationshipCardinality::ManyToOne,
        'from_field_id' => $from->id,
        'to_field_id' => $to->id,
    ]);

    expect($definition->cardinality)->toBe(RelationshipCardinality::ManyToOne)
        ->and($definition->directionFor($from))->toBe('from')
        ->and($definition->directionFor($to))->toBe('to')
        ->and($definition->isHeadless())->toBeFalse()
        ->and($from->relationshipDefinition()?->id)->toBe($definition->id);
});

it('supports headless definitions with no fields', function (): void {
    $definition = CustomFieldRelationship::factory()->create([
        'from_field_id' => null,
        'to_field_id' => null,
    ]);

    expect($definition->isHeadless())->toBeTrue();
});

it('rejects directionFor on an unrelated field', function (): void {
    $definition = CustomFieldRelationship::factory()->create();
    $stranger = CustomField::factory()->create(['type' => 'record']);

    $definition->directionFor($stranger);
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Run to verify failure**: `vendor/bin/pest --filter=RelationshipDefinitionModelTest`, fails: table/model missing.

- [ ] **Step 3: Implement**

Migration `database/migrations/create_custom_field_relationships_table.php` (same anonymous-class + feature-flag shape as the existing create migration):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureManager;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('custom-fields.database.table_names.custom_field_relationships'), function (Blueprint $table): void {
            $uniqueCode = ['code'];

            $table->id();

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
                $table->foreignId($tenantKey)->nullable()->index();
                $uniqueCode[] = $tenantKey;
            }

            $table->string('code');
            $table->string('from_entity_type');
            $table->string('to_entity_type');
            $table->string('cardinality');
            $table->foreignId('from_field_id')->nullable()->unique()
                ->constrained(config('custom-fields.database.table_names.custom_fields'))->nullOnDelete();
            $table->foreignId('to_field_id')->nullable()->unique()
                ->constrained(config('custom-fields.database.table_names.custom_fields'))->nullOnDelete();
            $table->boolean('is_symmetric')->default(false);
            $table->timestamps();

            $table->unique($uniqueCode);
        });
    }
};
```

Model `src/Models/CustomFieldRelationship.php`:

```php
<?php

declare(strict_types=1);

namespace Relaticle\CustomFields\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Relaticle\CustomFields\CustomFields;
use Relaticle\CustomFields\Database\Factories\CustomFieldRelationshipFactory;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

/**
 * @property int $id
 * @property string $code
 * @property string $from_entity_type
 * @property string $to_entity_type
 * @property RelationshipCardinality $cardinality
 * @property ?int $from_field_id
 * @property ?int $to_field_id
 * @property bool $is_symmetric
 */
#[ScopedBy([TenantScope::class])]
class CustomFieldRelationship extends Model
{
    /** @use HasFactory<CustomFieldRelationshipFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        if ($this->table === null) {
            $this->setTable(config('custom-fields.database.table_names.custom_field_relationships'));
        }

        parent::__construct($attributes);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cardinality' => RelationshipCardinality::class,
            'is_symmetric' => 'boolean',
        ];
    }

    /** @return BelongsTo<CustomField, $this> */
    public function fromField(): BelongsTo
    {
        return $this->belongsTo(CustomFields::customFieldModel(), 'from_field_id');
    }

    /** @return BelongsTo<CustomField, $this> */
    public function toField(): BelongsTo
    {
        return $this->belongsTo(CustomFields::customFieldModel(), 'to_field_id');
    }

    public function directionFor(CustomField $field): string
    {
        if ($field->getKey() === $this->from_field_id) {
            return 'from';
        }

        if ($field->getKey() === $this->to_field_id) {
            return 'to';
        }

        throw new InvalidArgumentException("Field [{$field->getKey()}] does not belong to relationship [{$this->code}].");
    }

    public function isHeadless(): bool
    {
        return $this->from_field_id === null && $this->to_field_id === null;
    }

    protected static function newFactory(): CustomFieldRelationshipFactory
    {
        return CustomFieldRelationshipFactory::new();
    }
}
```

Factory `database/factories/CustomFieldRelationshipFactory.php` (mirror `CustomFieldFactory` conventions; definition defaults `code => fake()->unique()->slug(2, '_')`, entity types `'post'`/`'user'`, cardinality `ManyToMany`, fields null, `is_symmetric => false`).

On `CustomField` add:

```php
public function relationshipDefinition(): ?CustomFieldRelationship
{
    return once(fn (): ?CustomFieldRelationship => CustomFieldRelationship::query()
        ->where('from_field_id', $this->getKey())
        ->orWhere('to_field_id', $this->getKey())
        ->first());
}
```

Register the migration name in `CustomFieldsServiceProvider` alongside the existing `create_custom_fields_table` entry.

- [ ] **Step 4: Run to verify pass**: `vendor/bin/pest --filter=RelationshipDefinitionModelTest`
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): add relationship definitions table and model"`

---

### Task 3: Links table and model (the edge ledger)

**Files:**
- Create: `database/migrations/create_custom_field_links_table.php`
- Create: `src/Models/CustomFieldLink.php`
- Create: `database/factories/CustomFieldLinkFactory.php`
- Modify: `src/CustomFieldsServiceProvider.php` (register migration)
- Test: `tests/Feature/Relationships/LinkModelTest.php`

**Interfaces:**
- Produces: model `CustomFieldLink` with `relationship_id, from_entity_type, from_entity_id, to_entity_type, to_entity_id, sort_order, active_from, active_until, created_by_type, created_by_id, source (string), confidence (?float)`; relations `relationship(): BelongsTo<CustomFieldRelationship>`, `fromEntity(): MorphTo`, `toEntity(): MorphTo`, `createdBy(): MorphTo`; scope `scopeActive(Builder $q)` (`whereNull('active_until')`); method `close(\Illuminate\Support\Carbon $at): void` (sets active_until, saves).
- Produces: source string constants on the model: `SOURCE_USER = 'user'`, `SOURCE_IMPORT = 'import'`, `SOURCE_MIGRATION = 'migration'`, `SOURCE_AI = 'ai_inferred'`.
- Produces: swap-registry entries `CustomFields::useLinkModel()`, `newLinkModel()`, `linkModel()` mirroring the existing trios; all later tasks resolve the link model through them.
- Table has `timestamps()` disabled (`$timestamps = false`) like `CustomFieldValue`; `active_from` is the creation time, set by PHP.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Models\CustomFieldLink;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

it('persists an active edge and closes it without deleting', function (): void {
    $definition = CustomFieldRelationship::factory()->create();

    $link = CustomFieldLink::factory()->create([
        'relationship_id' => $definition->id,
        'from_entity_type' => 'post', 'from_entity_id' => 1,
        'to_entity_type' => 'user', 'to_entity_id' => 2,
    ]);

    expect(CustomFieldLink::query()->active()->count())->toBe(1);

    $link->close(now());

    expect(CustomFieldLink::query()->active()->count())->toBe(0)
        ->and(CustomFieldLink::query()->count())->toBe(1)
        ->and($link->refresh()->active_until)->not->toBeNull();
});

it('refuses a duplicate active edge at the database level', function (): void {
    $definition = CustomFieldRelationship::factory()->create();
    $attributes = [
        'relationship_id' => $definition->id,
        'from_entity_type' => 'post', 'from_entity_id' => 1,
        'to_entity_type' => 'user', 'to_entity_id' => 2,
    ];

    CustomFieldLink::factory()->create($attributes);
    CustomFieldLink::factory()->create($attributes);
})->throws(Illuminate\Database\QueryException::class);

it('allows re-linking after a close', function (): void {
    $definition = CustomFieldRelationship::factory()->create();
    $attributes = [
        'relationship_id' => $definition->id,
        'from_entity_type' => 'post', 'from_entity_id' => 1,
        'to_entity_type' => 'user', 'to_entity_id' => 2,
    ];

    CustomFieldLink::factory()->create($attributes)->close(now());

    expect(CustomFieldLink::factory()->create($attributes))->toBeInstanceOf(CustomFieldLink::class);
});
```

- [ ] **Step 2: Run to verify failure**: `vendor/bin/pest --filter=LinkModelTest`

- [ ] **Step 3: Implement**

Migration `create_custom_field_links_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureManager;

return new class extends Migration
{
    public function up(): void
    {
        $links = config('custom-fields.database.table_names.custom_field_links');

        Schema::create($links, function (Blueprint $table): void {
            $table->id();

            if (FeatureManager::isEnabled(CustomFieldsFeature::SYSTEM_MULTI_TENANCY)) {
                $table->foreignId(config('custom-fields.database.column_names.tenant_foreign_key'))->nullable()->index();
            }

            $table->foreignId('relationship_id')
                ->constrained(config('custom-fields.database.table_names.custom_field_relationships'))
                ->cascadeOnDelete();

            $table->morphs('from_entity');
            $table->morphs('to_entity');

            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamp('active_from');
            $table->timestamp('active_until')->nullable();
            $table->nullableMorphs('created_by');
            $table->string('source', 32)->default('user');
            $table->float('confidence')->nullable();

            $table->index(['relationship_id', 'from_entity_id', 'active_until'], 'cf_links_from_idx');
            $table->index(['relationship_id', 'to_entity_id', 'active_until'], 'cf_links_to_idx');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX cf_links_active_edge_unique ON {$links} (relationship_id, from_entity_type, from_entity_id, to_entity_type, to_entity_id) WHERE active_until IS NULL");
        }
        // MySQL-family: duplicate-active-edge protection is app-level (LinkWriter). Documented caveat.
    }
};
```

Model `src/Models/CustomFieldLink.php` (same construction pattern as `CustomFieldValue`: `$timestamps = false`, `$guarded = []`, table from config, `#[ScopedBy([TenantScope::class])]`):

```php
<?php

declare(strict_types=1);

namespace Relaticle\CustomFields\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Relaticle\CustomFields\Database\Factories\CustomFieldLinkFactory;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

/**
 * @property int $id
 * @property int $relationship_id
 * @property string $from_entity_type
 * @property int|string $from_entity_id
 * @property string $to_entity_type
 * @property int|string $to_entity_id
 * @property ?int $sort_order
 * @property Carbon $active_from
 * @property ?Carbon $active_until
 * @property string $source
 * @property ?float $confidence
 * @property CustomFieldRelationship $relationship
 */
#[ScopedBy([TenantScope::class])]
class CustomFieldLink extends Model
{
    /** @use HasFactory<CustomFieldLinkFactory> */
    use HasFactory;

    public const string SOURCE_USER = 'user';

    public const string SOURCE_IMPORT = 'import';

    public const string SOURCE_MIGRATION = 'migration';

    public const string SOURCE_AI = 'ai_inferred';

    public $timestamps = false;

    protected $guarded = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        if ($this->table === null) {
            $this->setTable(config('custom-fields.database.table_names.custom_field_links'));
        }

        parent::__construct($attributes);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active_from' => 'datetime',
            'active_until' => 'datetime',
            'confidence' => 'float',
        ];
    }

    /** @return BelongsTo<CustomFieldRelationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(CustomFieldRelationship::class, 'relationship_id');
    }

    /** @return MorphTo<Model, $this> */
    public function fromEntity(): MorphTo
    {
        return $this->morphTo('from_entity');
    }

    /** @return MorphTo<Model, $this> */
    public function toEntity(): MorphTo
    {
        return $this->morphTo('to_entity');
    }

    /** @return MorphTo<Model, $this> */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('active_until');
    }

    public function close(Carbon $at): void
    {
        $this->active_until = $at;
        $this->save();
    }

    protected static function newFactory(): CustomFieldLinkFactory
    {
        return CustomFieldLinkFactory::new();
    }
}
```

Factory defaults: `active_from => now()`, `source => CustomFieldLink::SOURCE_USER`, `sort_order => 0`.

- [ ] **Step 4: Run to verify pass**
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): add edge ledger table and link model"`

---

### Task 4: CreateRelationshipDefinition service (and unpair/delete)

**Files:**
- Create: `src/Services/Relationships/CreateRelationshipDefinition.php`
- Create: `src/Services/Relationships/DeleteRelationshipDefinition.php`
- Create: `src/Data/RelationshipDefinitionData.php`
- Test: `tests/Feature/Relationships/CreateRelationshipDefinitionTest.php`

**Interfaces:**
- Consumes: `CustomFieldRelationship`, `RelationshipCardinality`, existing `CustomField` creation conventions (code, name, entity_type, type `'record'`).
- Produces: `CreateRelationshipDefinition::execute(RelationshipDefinitionData $data): CustomFieldRelationship`. `RelationshipDefinitionData` (spatie/laravel-data, matching `src/Data` conventions): `code, from_entity_type, to_entity_type, cardinality (RelationshipCardinality), is_symmetric (bool, default false), fromField (?FieldSlotData), toField (?FieldSlotData)` where `FieldSlotData` = `name (string), section_id (?int)`. Symmetric definitions require `from_entity_type === to_entity_type` and reject a `toField` (single slot).
- Produces: `DeleteRelationshipDefinition::execute(CustomFieldRelationship $definition, bool $deleteFields = false): void` closing nothing (FK cascade removes links), unpairing or deleting slot fields.
- Tenant: the service resolves the tenant exactly like `saveCustomFieldValue` (explicit arg, Filament tenant, `CustomFields::resolveTenantUsing`) and stamps it on the definition row AND both slot CustomField rows. Add a test that sets `TenantContextService::setTenantId()`, creates a definition, and asserts the definition and its fields are visible through the scoped query and carry the tenant id.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldLink;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\Services\Relationships\CreateRelationshipDefinition;
use Relaticle\CustomFields\Services\Relationships\DeleteRelationshipDefinition;

it('creates a paired definition with two record fields in one transaction', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'authorship',
        from_entity_type: 'post',
        to_entity_type: 'user',
        cardinality: RelationshipCardinality::ManyToOne,
        fromField: new FieldSlotData(name: 'Author'),
        toField: new FieldSlotData(name: 'Posts'),
    ));

    expect($definition->fromField)->not->toBeNull()
        ->and($definition->fromField->type)->toBe('record')
        ->and($definition->fromField->entity_type)->toBe('post')
        ->and($definition->toField->entity_type)->toBe('user')
        ->and(CustomField::query()->count())->toBe(2);
});

it('creates a one-way definition with a single field', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'referrer',
        from_entity_type: 'user',
        to_entity_type: 'user',
        cardinality: RelationshipCardinality::ManyToOne,
        fromField: new FieldSlotData(name: 'Referred by'),
    ));

    expect($definition->to_field_id)->toBeNull()
        ->and(CustomField::query()->count())->toBe(1);
});

it('creates a headless definition with no fields', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'works_with',
        from_entity_type: 'user',
        to_entity_type: 'user',
        cardinality: RelationshipCardinality::ManyToMany,
    ));

    expect($definition->isHeadless())->toBeTrue()
        ->and(CustomField::query()->count())->toBe(0);
});

it('rejects a symmetric definition across two entity types', function (): void {
    app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'spouse',
        from_entity_type: 'post',
        to_entity_type: 'user',
        cardinality: RelationshipCardinality::OneToOne,
        is_symmetric: true,
        fromField: new FieldSlotData(name: 'Spouse'),
    ));
})->throws(InvalidArgumentException::class);

it('unpairs on delete keeping fields when asked', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'authorship',
        from_entity_type: 'post',
        to_entity_type: 'user',
        cardinality: RelationshipCardinality::ManyToOne,
        fromField: new FieldSlotData(name: 'Author'),
        toField: new FieldSlotData(name: 'Posts'),
    ));
    CustomFieldLink::factory()->create(['relationship_id' => $definition->id]);

    app(DeleteRelationshipDefinition::class)->execute($definition, deleteFields: false);

    expect(CustomFieldRelationship::query()->count())->toBe(0)
        ->and(CustomFieldLink::query()->count())->toBe(0)
        ->and(CustomField::query()->count())->toBe(2);
});
```

- [ ] **Step 2: Run to verify failure**: `vendor/bin/pest --filter=CreateRelationshipDefinitionTest`

- [ ] **Step 3: Implement**

`src/Data/RelationshipDefinitionData.php` and `FieldSlotData` as plain spatie/laravel-data objects (copy constructor-promotion style from `src/Data/CustomFieldData.php`). Service:

```php
<?php

declare(strict_types=1);

namespace Relaticle\CustomFields\Services\Relationships;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Relaticle\CustomFields\CustomFields;
use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

final readonly class CreateRelationshipDefinition
{
    public function execute(RelationshipDefinitionData $data): CustomFieldRelationship
    {
        if ($data->is_symmetric && $data->from_entity_type !== $data->to_entity_type) {
            throw new InvalidArgumentException('A symmetric relationship requires matching entity types.');
        }

        if ($data->is_symmetric && $data->toField instanceof FieldSlotData) {
            throw new InvalidArgumentException('A symmetric relationship has a single field slot.');
        }

        return DB::transaction(function () use ($data): CustomFieldRelationship {
            $fromField = $data->fromField instanceof FieldSlotData
                ? $this->createSlotField($data->fromField, $data->from_entity_type)
                : null;

            $toField = $data->toField instanceof FieldSlotData
                ? $this->createSlotField($data->toField, $data->to_entity_type)
                : null;

            return CustomFieldRelationship::query()->create([
                'code' => $data->code,
                'from_entity_type' => $data->from_entity_type,
                'to_entity_type' => $data->to_entity_type,
                'cardinality' => $data->cardinality,
                'is_symmetric' => $data->is_symmetric,
                'from_field_id' => $fromField?->getKey(),
                'to_field_id' => $data->is_symmetric ? $fromField?->getKey() : $toField?->getKey(),
            ]);
        });
    }

    private function createSlotField(FieldSlotData $slot, string $entityType): mixed
    {
        return CustomFields::newCustomFieldModel()->newQuery()->create([
            'code' => Str::snake($slot->name),
            'name' => $slot->name,
            'type' => 'record',
            'entity_type' => $entityType,
            'custom_field_section_id' => $slot->section_id,
            'active' => true,
        ]);
    }
}
```

Symmetric note: `to_field_id` points at the same field as `from_field_id`, which satisfies "one field, both directions" and lets `directionFor` return `'from'` for it (first match wins). `DeleteRelationshipDefinition` deletes the definition (links cascade), then either deletes slot fields or leaves them as plain record fields with no definition (which Task 8's write path treats as a one-way definition-less error, so on unpair-keep it must create a one-way definition per kept field, same transaction). Include that in the implementation and assert it in the test above by checking `CustomFieldRelationship::query()->count()` after keep: adjust expectation to 2 one-way definitions replacing the pair. Update the test accordingly when implementing.

Wait, the test above expects 0 definitions after delete with kept fields. Decide: kept fields become one-way definitions (2 remain). The final test expectation is:

```php
expect(CustomFieldRelationship::query()->count())->toBe(2)
    ->and(CustomFieldRelationship::query()->whereNotNull('from_field_id')->count())->toBe(2)
    ->and(CustomFieldLink::query()->count())->toBe(0)
    ->and(CustomField::query()->count())->toBe(2);
```

Use this version in Step 1, not the earlier draft.

- [ ] **Step 4: Run to verify pass**
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): add definition create and delete services"`

---

### Task 5: Actor resolver contract

**Files:**
- Create: `src/Contracts/LinkActorResolver.php`
- Create: `src/Services/Relationships/AuthenticatedActorResolver.php`
- Modify: `src/CustomFieldsServiceProvider.php` (bind contract to default)
- Test: `tests/Feature/Relationships/LinkActorResolverTest.php`

**Interfaces:**
- Produces: `interface LinkActorResolver { public function resolve(): ?\Illuminate\Database\Eloquent\Model; }`. Default returns `auth()->user()`. Hosts rebind (Relaticle binds an agent-aware resolver in Plan 4).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Contracts\LinkActorResolver;
use Relaticle\CustomFields\Tests\Fixtures\Models\User;

it('resolves the authenticated user as actor', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(app(LinkActorResolver::class)->resolve())->toBeSameModel($user);
});

it('resolves null when unauthenticated', function (): void {
    expect(app(LinkActorResolver::class)->resolve())->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**
- [ ] **Step 3: Implement** the interface, the default resolver (`return auth()->user();`), and the singleton binding in the service provider's `packageRegistered()` (match how `EntityManagerInterface` is bound).
- [ ] **Step 4: Run to verify pass**
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): add link actor resolver contract"`

---

### Task 6: LinkWriter (diff apply, canonicalization, steal, events)

**Files:**
- Create: `src/Services/Relationships/LinkWriter.php`
- Create: `src/Events/RelationshipLinkCreated.php`, `src/Events/RelationshipLinkClosed.php`
- Test: `tests/Feature/Relationships/LinkWriterTest.php`

**Interfaces:**
- Consumes: `CustomFieldRelationship::directionFor()`, `CustomFieldLink` (Task 3), `LinkActorResolver` (Task 5), `CustomField::relationshipDefinition()` (Task 2).
- Produces: `LinkWriter::apply(Model&HasCustomFields $record, CustomField $field, array $targetIds, string $source = CustomFieldLink::SOURCE_USER): void`. `$targetIds` is the ordered id list from the payload (empty array clears). Emits one `RelationshipLinkCreated` per insert, one `RelationshipLinkClosed` per close; both events carry `public CustomFieldLink $link`.
- Behavior contract (all inside `DB::transaction`):
  1. Resolve definition + direction; symmetric edges canonicalize so `from_entity_id <= to_entity_id` (string comparison covers ULIDs and ints uniformly).
  2. Validate targets: every id in `$targetIds` must resolve to a row of the target entity type within the current tenant (the target model comes from the morph map). A missing or foreign id throws `ValidationException` (lang key `custom-fields::custom-fields.relationships.errors.unknown_target`); a bad id must never become a stored edge.
  3. Diff `$targetIds` against active links on this record's side. Unchanged: untouched (sort_order updated in place when order changed, no close/reopen).
  4. Removed: `close(now())`. Added: insert with `active_from = now()`, actor, source, sort_order = payload index, and `tenant_id` copied from the definition row.
  5. Single-target sides (per cardinality + direction) steal: close the displaced record's active edge before inserting.
  6. Concurrency: before the diff, `lockForUpdate()` the DEFINITION ROW (all drivers, no switch); skip the lock when cardinality is `many_to_many`. Catch `UniqueConstraintViolationException`, rethrow as `ValidationException::withMessages([$field->getFieldName() => __('custom-fields::custom-fields.relationships.errors.conflict')])`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Events\RelationshipLinkClosed;
use Relaticle\CustomFields\Events\RelationshipLinkCreated;
use Relaticle\CustomFields\Models\CustomFieldLink;
use Relaticle\CustomFields\Services\Relationships\CreateRelationshipDefinition;
use Relaticle\CustomFields\Services\Relationships\LinkWriter;
use Relaticle\CustomFields\Tests\Fixtures\Models\Post;
use Relaticle\CustomFields\Tests\Fixtures\Models\User;

function makeAuthorship(): array
{
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'authorship',
        from_entity_type: (new Post)->getMorphClass(),
        to_entity_type: (new User)->getMorphClass(),
        cardinality: RelationshipCardinality::ManyToOne,
        fromField: new FieldSlotData(name: 'Author'),
        toField: new FieldSlotData(name: 'Posts'),
    ));

    return [$definition, $definition->fromField, $definition->toField];
}

it('adds, keeps, and removes links from a diff', function (): void {
    [, $authorField] = makeAuthorship();
    $post = Post::factory()->create();
    [$a, $b] = User::factory()->count(2)->create();

    app(LinkWriter::class)->apply($post, $authorField, [$a->getKey()]);
    app(LinkWriter::class)->apply($post, $authorField, [$b->getKey()]);

    $active = CustomFieldLink::query()->active()->get();
    expect($active)->toHaveCount(1)
        ->and($active->first()->to_entity_id)->toEqual($b->getKey())
        ->and(CustomFieldLink::query()->count())->toBe(2);
});

it('clears every link for an empty payload', function (): void {
    [, $authorField] = makeAuthorship();
    $post = Post::factory()->create();
    $user = User::factory()->create();

    app(LinkWriter::class)->apply($post, $authorField, [$user->getKey()]);
    app(LinkWriter::class)->apply($post, $authorField, []);

    expect(CustomFieldLink::query()->active()->count())->toBe(0)
        ->and(CustomFieldLink::query()->count())->toBe(1);
});

it('writes the same edge whichever side applies it', function (): void {
    [, $authorField, $postsField] = makeAuthorship();
    $post = Post::factory()->create();
    $user = User::factory()->create();

    app(LinkWriter::class)->apply($post, $authorField, [$user->getKey()]);
    app(LinkWriter::class)->apply($user, $postsField, [$post->getKey()]);

    expect(CustomFieldLink::query()->count())->toBe(1);
});

it('steals a taken single side and closes the displaced edge', function (): void {
    [, $authorField] = makeAuthorship();
    [$postA, $postB] = Post::factory()->count(2)->create();
    $user = User::factory()->create();

    // many_to_one: each post has one author; user side holds many posts. Author side is single.
    app(LinkWriter::class)->apply($postA, $authorField, [$user->getKey()]);
    app(LinkWriter::class)->apply($postB, $authorField, [$user->getKey()]);

    expect(CustomFieldLink::query()->active()->count())->toBe(2);
});

it('emits created and closed events', function (): void {
    Event::fake([RelationshipLinkCreated::class, RelationshipLinkClosed::class]);
    [, $authorField] = makeAuthorship();
    $post = Post::factory()->create();
    $user = User::factory()->create();

    app(LinkWriter::class)->apply($post, $authorField, [$user->getKey()]);
    app(LinkWriter::class)->apply($post, $authorField, []);

    Event::assertDispatchedTimes(RelationshipLinkCreated::class, 1);
    Event::assertDispatchedTimes(RelationshipLinkClosed::class, 1);
});

it('stamps actor and source on the edge', function (): void {
    [, $authorField] = makeAuthorship();
    $actor = User::factory()->create();
    $this->actingAs($actor);
    $post = Post::factory()->create();
    $user = User::factory()->create();

    app(LinkWriter::class)->apply($post, $authorField, [$user->getKey()]);

    $link = CustomFieldLink::query()->sole();
    expect($link->created_by_id)->toEqual($actor->getKey())
        ->and($link->source)->toBe(CustomFieldLink::SOURCE_USER);
});

it('canonicalizes symmetric edges to one row', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'spouse',
        from_entity_type: (new User)->getMorphClass(),
        to_entity_type: (new User)->getMorphClass(),
        cardinality: RelationshipCardinality::OneToOne,
        is_symmetric: true,
        fromField: new FieldSlotData(name: 'Spouse'),
    ));
    [$a, $b] = User::factory()->count(2)->create();

    app(LinkWriter::class)->apply($a, $definition->fromField, [$b->getKey()]);
    app(LinkWriter::class)->apply($b, $definition->fromField, [$a->getKey()]);

    expect(CustomFieldLink::query()->count())->toBe(1);
});
```

Note the steal test: `many_to_one` means the FROM side (post's Author) is single, and one user may hold many posts. Two posts pointing at one user is legal; both stay active. The genuine steal case is `one_to_one`: add a dedicated test where linking a spouse to an already-married record closes the displaced edge; assert `active()->count() === 1` and total rows 2. Include it in Step 1.

- [ ] **Step 2: Run to verify failure**

- [ ] **Step 3: Implement** `LinkWriter` per the behavior contract. Core shape:

```php
public function apply(Model $record, CustomField $field, array $targetIds, string $source = CustomFieldLink::SOURCE_USER): void
{
    $definition = $field->relationshipDefinition();

    if ($definition === null) {
        throw new InvalidArgumentException("Record field [{$field->code}] has no relationship definition.");
    }

    DB::transaction(function () use ($record, $definition, $field, $targetIds, $source): void {
        $this->lock($definition);

        $direction = $definition->is_symmetric ? 'from' : $definition->directionFor($field);
        $now = now();
        $actor = $this->actorResolver->resolve();

        $current = $this->activeLinksFor($definition, $record, $direction)->get();
        $currentIds = $current->map(fn (CustomFieldLink $link): string => (string) $this->otherEndId($link, $record))->all();
        $wanted = array_values(array_map(strval(...), $targetIds));

        foreach ($current as $link) {
            if (! in_array((string) $this->otherEndId($link, $record), $wanted, true)) {
                $link->close($now);
                event(new RelationshipLinkClosed($link));
            }
        }

        foreach ($wanted as $index => $targetId) {
            if (in_array($targetId, $currentIds, true)) {
                $this->syncSortOrder($current, $record, $targetId, $index);

                continue;
            }

            $this->closeDisplacedSingles($definition, $direction, $record, $targetId, $now);
            $link = $this->insert($definition, $direction, $record, $targetId, $index, $now, $actor, $source);
            event(new RelationshipLinkCreated($link));
        }
    });
}
```

Private helpers each a few lines: `lock()` (definition-row `lockForUpdate`, skipped for many_to_many), `assertTargetsExist()` (morph-map resolve + tenant-scoped whereIn count check), `activeLinksFor()` (where from/to side matches record, symmetric matches either side), `otherEndId()`, `closeDisplacedSingles()` (per `cardinality->fromSideIsSingle()/toSideIsSingle()` close conflicting active edges on both constrained ends, emitting Closed), `insert()` (canonical order for symmetric, tenant_id from the definition), `syncSortOrder()`. Test additions: a tenant round-trip (write with tenant context set, read back through the scoped query) and a cross-tenant target id rejected with ValidationException. Catch `UniqueConstraintViolationException` around the transaction and rethrow as `ValidationException` with lang key `custom-fields::custom-fields.relationships.errors.conflict` (add to lang file: `'conflict' => 'This link conflicts with a concurrent change. Reload and retry.'`).

- [ ] **Step 4: Run to verify pass**: full filter run plus `vendor/bin/pest --filter=LinkModelTest` (regression).
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): add diff-based link writer with events"`

---

### Task 7: Write-path fork in UsesCustomFields

**Files:**
- Modify: `src/Models/Concerns/UsesCustomFields.php` (`saveCustomFieldValue`)
- Test: `tests/Feature/Relationships/RecordFieldWritePathTest.php`

**Interfaces:**
- Consumes: `LinkWriter::apply()` (Task 6).
- Produces: unchanged public contract. `$post->update(['custom_fields' => ['author' => [$id]]])` now writes links for record-type fields and value rows for everything else. `saveCustomFields()` keeps iterating every field; for record fields a missing key still means "clear", identical to today's semantics.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Models\CustomFieldLink;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Services\Relationships\CreateRelationshipDefinition;
use Relaticle\CustomFields\Tests\Fixtures\Models\Post;
use Relaticle\CustomFields\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'authorship',
        from_entity_type: (new Post)->getMorphClass(),
        to_entity_type: (new User)->getMorphClass(),
        cardinality: RelationshipCardinality::ManyToOne,
        fromField: new FieldSlotData(name: 'Author'),
    ));
});

it('writes links, not value rows, through the custom_fields payload', function (): void {
    $user = User::factory()->create();

    $post = Post::factory()->create(['custom_fields' => ['author' => [$user->getKey()]]]);

    expect(CustomFieldLink::query()->active()->count())->toBe(1)
        ->and(CustomFieldValue::query()->where('custom_field_id', $this->definition->from_field_id)->count())->toBe(0);
});

it('clears links through an empty payload value', function (): void {
    $user = User::factory()->create();
    $post = Post::factory()->create(['custom_fields' => ['author' => [$user->getKey()]]]);

    $post->update(['custom_fields' => ['author' => []]]);

    expect(CustomFieldLink::query()->active()->count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify failure**

- [ ] **Step 3: Implement**

In `saveCustomFieldValue()`, before the value-row path:

```php
if ($customField->typeData->key === 'record') {
    app(LinkWriter::class)->apply($this, $customField, array_values(array_filter((array) $value, fn (mixed $id): bool => $id !== null && $id !== '')));

    return;
}
```

Confirm the exact type-key accessor against `HasFieldType` (`$customField->typeData->key`) when implementing; if the property is named differently use the real one everywhere.

- [ ] **Step 4: Run to verify pass** plus the package's existing write-path tests: `vendor/bin/pest tests/Feature --parallel` (record-field tests that asserted json_value will fail; update those assertions to links in this task, never weaken unrelated ones).
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): route record fields through the link writer"`

---

### Task 8: Read path returns id arrays from links

**Files:**
- Modify: `src/Models/Concerns/UsesCustomFields.php` (`getCustomFieldValue`), add relations `outgoingLinks()`, `incomingLinks()`
- Test: `tests/Feature/Relationships/RecordFieldReadPathTest.php`

**Interfaces:**
- Produces: for record fields `getCustomFieldValue()` returns `array<int, int|string>` ordered by `sort_order` (empty array when none), identical shape to the old json_value read. Trait relations: `outgoingLinks(): MorphMany` (`CustomFieldLink`, morph name `from_entity`), `incomingLinks(): MorphMany` (`to_entity`).
- Produces: history accessor used by later plans: `CustomFieldLink::query()->withoutGlobalScopes()` is NOT the API; historic reads use the natural query `->where(...)->whereNotNull('active_until')`. Nothing extra to build here.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\Relationships\CreateRelationshipDefinition;
use Relaticle\CustomFields\Tests\Fixtures\Models\Post;
use Relaticle\CustomFields\Tests\Fixtures\Models\User;

it('reads ordered ids from both sides of one edge set', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'reviewers',
        from_entity_type: (new Post)->getMorphClass(),
        to_entity_type: (new User)->getMorphClass(),
        cardinality: RelationshipCardinality::ManyToMany,
        fromField: new FieldSlotData(name: 'Reviewers'),
        toField: new FieldSlotData(name: 'Reviewing'),
    ));

    $post = Post::factory()->create();
    [$a, $b] = User::factory()->count(2)->create();

    $post->update(['custom_fields' => ['reviewers' => [$b->getKey(), $a->getKey()]]]);

    expect($post->refresh()->getCustomFieldValue($definition->fromField))
        ->toBe([$b->getKey(), $a->getKey()])
        ->and($a->refresh()->getCustomFieldValue($definition->toField))
        ->toBe([$post->getKey()]);
});

it('returns an empty array for an unlinked record field', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'reviewers',
        from_entity_type: (new Post)->getMorphClass(),
        to_entity_type: (new User)->getMorphClass(),
        cardinality: RelationshipCardinality::ManyToMany,
        fromField: new FieldSlotData(name: 'Reviewers'),
    ));

    expect(Post::factory()->create()->getCustomFieldValue($definition->fromField))->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure**

- [ ] **Step 3: Implement**

In `getCustomFieldValue()` before the value lookup:

```php
if ($customField->typeData->key === 'record') {
    $definition = $customField->relationshipDefinition();

    if ($definition === null) {
        return [];
    }

    $direction = $definition->is_symmetric ? 'both' : $definition->directionFor($customField);

    return app(LinkReader::class)->orderedIdsFor($this, $definition, $direction);
}
```

`LinkReader` (`src/Services/Relationships/LinkReader.php`, new file in this task, `final readonly`): `orderedIdsFor(Model $record, CustomFieldRelationship $definition, string $direction): array` queries active links where the record matches the given side ('both' matches either for symmetric), orders by `sort_order`, returns the other end's ids. Prefer loaded `outgoingLinks`/`incomingLinks` relations when `relationLoaded()` to avoid N+1 (the preloading wiring itself lands with the table components in Task 9).

- [ ] **Step 4: Run to verify pass** plus a full `vendor/bin/pest --parallel` sweep; fix any record-field consumer that asserted the old storage.
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): read record field values from the edge ledger"`

---

### Task 9: Filament components on link storage; sortable and searchable flip

**Files:**
- Modify: `src/Filament/Integration/Components/Tables/Filters/RecordFilter.php` (query closure)
- Modify: `src/Filament/Integration/Components/Tables/Columns/RecordColumn.php`
- Modify: `src/FieldTypeSystem/Definitions/RecordFieldType.php` (`sortable(false)` and `searchable(false)` become true)
- Modify: `src/Models/Concerns/UsesCustomFields.php` (`scopeWithCustomFieldValues` eager-loads `outgoingLinks`, `incomingLinks`)
- Test: `tests/Feature/Relationships/RecordTableSurfacesTest.php` (extend existing patterns from `tests/Feature/Filament`)

**Interfaces:**
- Consumes: `LinkReader` (Task 8), definitions (Task 2).
- Produces: `RecordFilter` filters via `whereExists` on the links table (both single and multi variants, which kills the reported single-value filter bug); `RecordColumn` sorts by the linked target's primary attribute via a correlated subquery and searches via `whereExists` against the entity's search attributes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\Relationships\CreateRelationshipDefinition;
use Relaticle\CustomFields\Tests\Fixtures\Models\Post;
use Relaticle\CustomFields\Tests\Fixtures\Models\User;

use function Pest\Livewire\livewire;

it('filters posts by a single-value record field', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'author',
        from_entity_type: (new Post)->getMorphClass(),
        to_entity_type: (new User)->getMorphClass(),
        cardinality: RelationshipCardinality::ManyToOne,
        fromField: new FieldSlotData(name: 'Author'),
    ));

    $author = User::factory()->create();
    $match = Post::factory()->create(['custom_fields' => ['author' => [$author->getKey()]]]);
    $miss = Post::factory()->create();

    livewire(\Relaticle\CustomFields\Tests\Fixtures\Filament\Resources\Posts\Pages\ListPosts::class)
        ->filterTable($definition->fromField->getFieldName(), ['values' => [$author->getKey()]])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$miss]);
});
```

Locate the real list-page fixture class under `tests/Fixtures` before writing (search `tests/Fixtures -name '*ListPosts*'` or the closest existing table test in `tests/Feature/Filament`); reuse whatever page those tests drive. Add a sort assertion (`sortTable` by the field, assert order) and a search assertion in the same file, modeled on the closest existing table test.

- [ ] **Step 2: Run to verify failure** (filter finds nothing while links exist).

- [ ] **Step 3: Implement**

`RecordFilter::make()` query closure replacement:

```php
$filter->query(function (array $data, Builder $query) use ($customField): Builder {
    if (empty($data['values'])) {
        return $query;
    }

    $definition = $customField->relationshipDefinition();

    if ($definition === null) {
        return $query;
    }

    $direction = $definition->is_symmetric ? 'from' : $definition->directionFor($customField);
    $links = config('custom-fields.database.table_names.custom_field_links');
    [$ownId, $otherId] = $direction === 'from'
        ? ["{$links}.from_entity_id", "{$links}.to_entity_id"]
        : ["{$links}.to_entity_id", "{$links}.from_entity_id"];

    return $query->whereExists(function ($sub) use ($links, $definition, $ownId, $otherId, $data, $query): void {
        $sub->from($links)
            ->where("{$links}.relationship_id", $definition->id)
            ->whereNull("{$links}.active_until")
            ->whereColumn($ownId, $query->getModel()->getTable().'.'.$query->getModel()->getKeyName())
            ->whereIn($otherId, $data['values']);
    });
});
```

Symmetric definitions need the mirrored branch OR'd in (`from` side matches record and target in `to`, or vice versa). `RecordColumn`: swap its state/search/sort hooks to link queries the same way; flip the two flags in `RecordFieldType::configure()`; extend `scopeWithCustomFieldValues` with `->with(['outgoingLinks', 'incomingLinks'])`.

- [ ] **Step 4: Run to verify pass** plus the whole Filament suite: `vendor/bin/pest tests/Feature/Filament --parallel`.
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): link-backed table filter, sort, and search for record fields"`

---

### Task 10: Cardinality validation layer

**Files:**
- Create: `src/Rules/CardinalityRule.php` (follow existing `src/Rules` conventions)
- Modify: `src/Services/ValidationService.php` (attach rule for record fields with a definition)
- Test: `tests/Feature/Relationships/CardinalityValidationTest.php`

**Interfaces:**
- Consumes: definitions and `RelationshipCardinality`.
- Produces: validation failure message before any write when the payload violates the field's side cap: a single side receives > 1 id (`max:1`-style message via lang key `custom-fields::custom-fields.relationships.errors.single_value`), lang entry: `'single_value' => 'This relationship holds a single record.'`. The steal path stays legal: 1 id into a taken slot validates and the writer resolves it.

- [ ] **Step 1: Write the failing test**

```php
it('rejects two ids on a single-cardinality side', function (): void {
    $definition = app(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
        code: 'author',
        from_entity_type: (new Post)->getMorphClass(),
        to_entity_type: (new User)->getMorphClass(),
        cardinality: RelationshipCardinality::ManyToOne,
        fromField: new FieldSlotData(name: 'Author'),
    ));
    [$a, $b] = User::factory()->count(2)->create();

    Post::factory()->create(['custom_fields' => ['author' => [$a->getKey(), $b->getKey()]]]);
})->throws(Illuminate\Validation\ValidationException::class);
```

(Imports as in Task 7's test file. If the package validates only through form/request layers rather than model writes, attach the rule in `LinkWriter::apply()` as a guard instead, keep the same test, and note the placement in the commit body.)

- [ ] **Step 2: Run to verify failure**
- [ ] **Step 3: Implement** the rule (count check against `fromSideIsSingle()`/`toSideIsSingle()` for the field's direction) and wire it where ValidationService builds record-field rules.
- [ ] **Step 4: Run to verify pass**
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): validate cardinality before link writes"`

---

### Task 11: Entity deletion behavior

**Files:**
- Modify: `src/Models/Concerns/UsesCustomFields.php` (the existing `static::deleting` hook)
- Test: `tests/Feature/Relationships/EntityDeletionTest.php`

**Interfaces:**
- Produces: force-delete (or non-soft-delete models) hard-deletes every link touching the record (both sides, any definition). Soft-delete leaves links untouched; `LinkReader` results are unaffected (display-layer resolvers already handle missing/trashed targets; verify with test).

- [ ] **Step 1: Write the failing test**

```php
it('hard-deletes links on force delete from either side', function (): void {
    // build authorship as in Task 7, link post->user, then:
    $post->forceDelete();

    expect(CustomFieldLink::query()->count())->toBe(0);
});

it('keeps links when a record is soft-deleted', function (): void {
    // Post fixture must use SoftDeletes; if none of the fixtures do, add a
    // SoftPost fixture model + migration under tests/Fixtures following Post exactly.
    $post->delete();

    expect(CustomFieldLink::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run to verify failure**
- [ ] **Step 3: Implement** in the existing deleting hook, next to `customFieldValues()->delete()`:

```php
CustomFieldLink::query()
    ->where(fn ($q) => $q
        ->where(fn ($q2) => $q2->where('from_entity_type', $model->getMorphClass())->where('from_entity_id', $model->getKey()))
        ->orWhere(fn ($q2) => $q2->where('to_entity_type', $model->getMorphClass())->where('to_entity_id', $model->getKey())))
    ->delete();
```

- [ ] **Step 4: Run to verify pass**
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): cascade link cleanup on force delete"`

---

### Task 12: record-links migration step in the existing upgrade framework

The package already ships `custom-fields:upgrade` with an `UpgradeStep`/`UpgradeStepResult` framework, dry-run support, and a `ValidateSchemaStep` gate (`src/Console/Commands/Upgrade/`). Do NOT create a standalone command; add steps to that framework. Model the step class on `MigrateLookupFieldsStep` (including `withoutGlobalScopes()` iteration across tenants).

**Files:**
- Create: `src/Console/Commands/Upgrade/Steps/MigrateRecordLinksStep.php`
- Create: `src/Console/Commands/Upgrade/Steps/PurgeMigratedRecordValuesStep.php` (opt-in, runs only via an explicit flag/prompt)
- Modify: `src/Console/Commands/UpgradeCommand.php` (register steps), `.../Steps/ValidateSchemaStep.php` (fail with guidance when record-type values still live in json_value)
- Test: `tests/Feature/Commands/UpgradeLinksStepTest.php` (existing `tests/Feature/Commands` conventions)

**Interfaces:**
- Consumes: everything above.
- Produces (`MigrateRecordLinksStep`): for every record-type `CustomField` without a definition: create a one-way definition (`code` = field code, `from_entity_type` = field entity_type, `to_entity_type` = field `lookup_type`, cardinality `ManyToMany` when `settings->allow_multiple` else `ManyToOne`, `from_field_id` = field, tenant from the field row). Then explode each of its `custom_field_values.json_value` arrays into link rows (`source = migration`, `active_from = now()`, `sort_order` = array index, tenant copied from the value row). The old value rows are KEPT: `PurgeMigratedRecordValuesStep` deletes them in a separate, explicitly invoked pass after the host has verified the migration, so rollback stays trivial at every point.
- Idempotent: an existing definition is reused, never a skip reason on its own; a value already represented by a matching link (same field, entity, target) is skipped, everything else still migrates. Reruns are no-ops. Dry-run prints per-field counts and writes nothing. Output before processing each field (project convention), summary after.

- [ ] **Step 1: Write the failing test**

```php
it('migrates json_value arrays into definitions and links', function (): void {
    $field = CustomField::factory()->create([
        'type' => 'record',
        'entity_type' => (new Post)->getMorphClass(),
        'lookup_type' => (new User)->getMorphClass(),
    ]);
    $post = Post::factory()->create();
    $user = User::factory()->create();
    CustomFieldValue::query()->create([
        'entity_type' => $post->getMorphClass(), 'entity_id' => $post->getKey(),
        'custom_field_id' => $field->id, 'json_value' => [$user->getKey()],
    ]);

    $this->artisan('custom-fields:upgrade')->assertSuccessful();

    expect(CustomFieldRelationship::query()->count())->toBe(1)
        ->and(CustomFieldLink::query()->active()->count())->toBe(1)
        ->and(CustomFieldValue::query()->where('custom_field_id', $field->id)->count())->toBe(1)
        ->and($post->refresh()->getCustomFieldValue($field->refresh()))->toBe([$user->getKey()]);
});

it('purges old value rows only in the explicit purge pass', function (): void {
    // arrange + migrate as above; then run the purge step and expect the value rows gone
    // while links and reads stay identical.
});

it('dry-run reports and writes nothing', function (): void {
    // same arrange; then:
    $this->artisan('custom-fields:upgrade', ['--dry-run' => true])->assertSuccessful();

    expect(CustomFieldLink::query()->count())->toBe(0)
        ->and(CustomFieldValue::query()->count())->toBe(1);
});

it('is idempotent across reruns', function (): void {
    // arrange, run twice, expect counts unchanged after the second run
});

it('migrates remaining values for a field that already has a definition', function (): void {
    // arrange a record field WITH a definition but json_value rows still present;
    // run; expect links created for those values, no duplicate definition.
});
```

- [ ] **Step 2: Run to verify failure**
- [ ] **Step 3: Implement** the steps (chunked value iteration, `DB::transaction` per field, `$command->info("Migrating field {$field->code}...")` before each, `UpgradeStepResult` summary after; `withoutGlobalScopes()` everywhere, tenant copied row by row).
- [ ] **Step 4: Run to verify pass**, then the ENTIRE suite once: `vendor/bin/pest --parallel`.
- [ ] **Step 5: Gates + commit**: `git commit -m "feat(relationships): add record-links migration step to upgrade command"`

---

## Plan self-review notes

- Spec coverage, sections 1 and 2: definitions (T2, T4), ledger (T3), write path and contract (T6, T7), actor (T5), read path (T8), table surfaces + filter-bug death (T9), validation wall (T10), deletion semantics (T11), migration (T12). Spec 1.2 index enforcement carries the documented refinement (advisory-lock strategy) at the top of this plan.
- Deliberately out of Plan 1: substrate-independent housekeeping runs BEFORE this plan (Plan 0); definitions management UI, creation modal, flavor registry (Plan 2); lookup_type retirement and the reflection-helper deletion (Plan 3, after T12 has migrated data and Plan 2 has replaced FieldForm's record path); Relaticle bindings, timeline listener, GetRelatedRecordsTool, path-repo wiring (Plan 4).
- Executor instruction: when reality contradicts a code sketch here (property names, fixture classes), the codebase wins; verify with a search before renaming anything in tests.
