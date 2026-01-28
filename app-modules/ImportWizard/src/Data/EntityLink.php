<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\ImportWizard\Enums\EntityLinkSource;
use Relaticle\ImportWizard\Enums\EntityLinkStorage;
use Relaticle\ImportWizard\Support\EntityLinkStorage\CustomFieldValueStorage;
use Relaticle\ImportWizard\Support\EntityLinkStorage\EntityLinkStorageInterface;
use Relaticle\ImportWizard\Support\EntityLinkStorage\ForeignKeyStorage;
use Relaticle\ImportWizard\Support\EntityLinkStorage\MorphToManyStorage;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Unified entity link definition for import mapping.
 *
 * EntityLinks represent connections to other entities, replacing the separate
 * RelationshipField system. They can come from two sources:
 * - Hardcoded relationship definitions in importers
 * - Record-type custom fields from the database
 *
 * Both are displayed in a unified "Link to Records" UI section.
 */
final class EntityLink extends Data
{
    /**
     * @param  string  $key  Unique identifier (e.g., 'company' or 'custom_fields_linked_company')
     * @param  EntityLinkSource  $source  Where this link comes from (Relationship or CustomField)
     * @param  string  $targetEntity  Entity alias (e.g., 'company', 'people')
     * @param  class-string<Model>  $targetModelClass  The target model class
     * @param  array<MatchableField>  $matchableFields  Fields that can match related records
     * @param  EntityLinkStorage  $storageType  How the link is persisted
     * @param  string  $label  Display label
     * @param  bool  $allowMultiple  Whether multiple values are allowed
     * @param  bool  $canCreate  Whether new related records can be created
     * @param  string|null  $foreignKey  FK column (for ForeignKey storage)
     * @param  string|null  $morphRelation  Relation name (for MorphToMany storage)
     * @param  string|null  $customFieldCode  Custom field code (for CustomFieldValue storage)
     * @param  array<string>  $guesses  Column name aliases for auto-mapping
     * @param  int|null  $sortOrder  Display order for custom field entity links
     */
    public function __construct(
        public readonly string $key,
        public readonly EntityLinkSource $source,
        public readonly string $targetEntity,
        public readonly string $targetModelClass,
        #[DataCollectionOf(MatchableField::class)]
        public readonly array $matchableFields = [],
        public readonly EntityLinkStorage $storageType = EntityLinkStorage::ForeignKey,
        public readonly string $label = '',
        public readonly bool $allowMultiple = false,
        public readonly bool $canCreate = false,
        public readonly ?string $foreignKey = null,
        public readonly ?string $morphRelation = null,
        public readonly ?string $customFieldCode = null,
        public readonly array $guesses = [],
        public readonly ?int $sortOrder = null,
    ) {}

    /**
     * Create new instance with selective property overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            key: $overrides['key'] ?? $this->key,
            source: $overrides['source'] ?? $this->source,
            targetEntity: $overrides['targetEntity'] ?? $this->targetEntity,
            targetModelClass: $overrides['targetModelClass'] ?? $this->targetModelClass,
            matchableFields: $overrides['matchableFields'] ?? $this->matchableFields,
            storageType: $overrides['storageType'] ?? $this->storageType,
            label: $overrides['label'] ?? $this->label,
            allowMultiple: $overrides['allowMultiple'] ?? $this->allowMultiple,
            canCreate: $overrides['canCreate'] ?? $this->canCreate,
            foreignKey: $overrides['foreignKey'] ?? $this->foreignKey,
            morphRelation: $overrides['morphRelation'] ?? $this->morphRelation,
            customFieldCode: $overrides['customFieldCode'] ?? $this->customFieldCode,
            guesses: $overrides['guesses'] ?? $this->guesses,
            sortOrder: $overrides['sortOrder'] ?? $this->sortOrder,
        );
    }

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Create a belongsTo relationship link.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function belongsTo(string $name, string $modelClass): self
    {
        return new self(
            key: $name,
            source: EntityLinkSource::Relationship,
            targetEntity: self::getEntityAliasForModel($modelClass),
            targetModelClass: $modelClass,
            storageType: EntityLinkStorage::ForeignKey,
            label: ucfirst($name),
        );
    }

    /**
     * Create a morphToMany relationship link.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function morphToMany(string $name, string $modelClass): self
    {
        return new self(
            key: $name,
            source: EntityLinkSource::Relationship,
            targetEntity: self::getEntityAliasForModel($modelClass),
            targetModelClass: $modelClass,
            storageType: EntityLinkStorage::MorphToMany,
            label: ucfirst($name),
            allowMultiple: true,
            morphRelation: $name,
        );
    }

    /**
     * Create an entity link from a Record-type custom field.
     */
    public static function fromCustomField(CustomField $customField): self
    {
        $lookupType = $customField->lookup_type;

        // Use Laravel's morph map to resolve lookup_type to model class
        $modelClass = Relation::getMorphedModel($lookupType);

        if ($modelClass === null) {
            throw new \InvalidArgumentException("Unknown lookup type: {$lookupType}");
        }

        return new self(
            key: "custom_fields_{$customField->code}",
            source: EntityLinkSource::CustomField,
            targetEntity: $lookupType,
            targetModelClass: $modelClass,
            matchableFields: self::getUniqueMatchableFieldsForEntity($modelClass),
            storageType: EntityLinkStorage::CustomFieldValue,
            label: $customField->name,
            allowMultiple: $customField->typeData->supportsMultiValue ?? false,
            customFieldCode: $customField->code,
            guesses: [
                $customField->code,
                $customField->name,
                strtolower($customField->name),
                str_replace(' ', '_', strtolower($customField->name)),
            ],
            sortOrder: $customField->sort_order,
        );
    }

    // =========================================================================
    // PRE-CONFIGURED ENTITY LINKS (replacing RelationshipField statics)
    // =========================================================================

    /**
     * Pre-configured company relationship.
     */
    public static function company(): self
    {
        return self::belongsTo('company', Company::class)
            ->matchableFields([
                MatchableField::id(),
                MatchableField::domain('custom_fields_domains'),
                MatchableField::name(),
            ])
            ->foreignKey('company_id')
            ->guess([
                'company', 'company_name', 'organization', 'account',
                'employer', 'company id', 'company_id',
            ]);
    }

    /**
     * Pre-configured contact relationship.
     */
    public static function contact(): self
    {
        return self::belongsTo('contact', People::class)
            ->matchableFields([
                MatchableField::id(),
                MatchableField::email('custom_fields_emails'),
                MatchableField::phone('custom_fields_phone_number'),
                MatchableField::name(),
            ])
            ->foreignKey('contact_id')
            ->guess([
                'contact', 'contact_name', 'person', 'contact_id',
                'person_id', 'people_id',
            ]);
    }

    /**
     * Pre-configured polymorphic companies relationship.
     */
    public static function polymorphicCompanies(): self
    {
        return self::morphToMany('companies', Company::class)
            ->matchableFields([
                MatchableField::id(),
                MatchableField::domain('custom_fields_domains'),
                MatchableField::name(),
            ])
            ->guess([
                'company', 'companies', 'company_name', 'company_id',
            ]);
    }

    /**
     * Pre-configured polymorphic people relationship.
     */
    public static function polymorphicPeople(): self
    {
        return self::morphToMany('people', People::class)
            ->matchableFields([
                MatchableField::id(),
                MatchableField::email('custom_fields_emails'),
                MatchableField::phone('custom_fields_phone_number'),
                MatchableField::name(),
            ])
            ->guess([
                'person', 'people', 'contact', 'contact_name', 'person_id',
            ]);
    }

    /**
     * Pre-configured polymorphic opportunities relationship.
     */
    public static function polymorphicOpportunities(): self
    {
        return self::morphToMany('opportunities', Opportunity::class)
            ->matchableFields([
                MatchableField::id(),
            ])
            ->guess([
                'opportunity', 'opportunities', 'deal', 'opportunity_id',
            ]);
    }

    // =========================================================================
    // BUILDER METHODS (immutable)
    // =========================================================================

    /**
     * Set matchable fields for this entity link.
     *
     * @param  array<MatchableField>  $matchableFields
     */
    public function matchableFields(array $matchableFields): self
    {
        return $this->cloneWith(['matchableFields' => $matchableFields]);
    }

    /**
     * Set the foreign key column.
     */
    public function foreignKey(string $foreignKey): self
    {
        return $this->cloneWith(['foreignKey' => $foreignKey]);
    }

    /**
     * Set the morph relation name.
     */
    public function morphRelation(string $relation): self
    {
        return $this->cloneWith(['morphRelation' => $relation]);
    }

    /**
     * Set column name aliases for auto-mapping.
     *
     * @param  array<string>  $guesses
     */
    public function guess(array $guesses): self
    {
        return $this->cloneWith(['guesses' => $guesses]);
    }

    /**
     * Set whether new related records can be created.
     */
    public function canCreate(bool $canCreate = true): self
    {
        return $this->cloneWith(['canCreate' => $canCreate]);
    }

    /**
     * Set the display label.
     */
    public function label(string $label): self
    {
        return $this->cloneWith(['label' => $label]);
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    /**
     * Get the highest priority matchable field.
     */
    public function getHighestPriorityMatcher(): ?MatchableField
    {
        if ($this->matchableFields === []) {
            return null;
        }

        return collect($this->matchableFields)
            ->sortByDesc(fn (MatchableField $field): int => $field->priority)
            ->first();
    }

    /**
     * Get a matchable field by key.
     */
    public function getMatcher(string $field): ?MatchableField
    {
        return collect($this->matchableFields)
            ->first(fn (MatchableField $m): bool => $m->field === $field);
    }

    /**
     * Check if this entity link matches a given column header.
     */
    public function matchesHeader(string $header): bool
    {
        $normalized = strtolower(trim($header));

        if ($normalized === strtolower($this->key)) {
            return true;
        }

        if ($normalized === strtolower($this->label)) {
            return true;
        }

        return array_any($this->guesses, fn ($guess): bool => strtolower($guess) === $normalized);
    }

    /**
     * Get the icon for this entity link from the Entities registry.
     */
    public function icon(): string
    {
        return Entities::getEntity($this->targetEntity)?->getIcon() ?? 'heroicon-o-link';
    }

    /**
     * Check if this is a belongsTo (foreign key) storage type.
     */
    public function isForeignKey(): bool
    {
        return $this->storageType === EntityLinkStorage::ForeignKey;
    }

    /**
     * Check if this is a morphToMany storage type.
     */
    public function isMorphToMany(): bool
    {
        return $this->storageType === EntityLinkStorage::MorphToMany;
    }

    /**
     * Check if this is a custom field value storage type.
     */
    public function isCustomFieldValue(): bool
    {
        return $this->storageType === EntityLinkStorage::CustomFieldValue;
    }

    /**
     * Check if this link comes from a relationship definition.
     */
    public function isFromRelationship(): bool
    {
        return $this->source === EntityLinkSource::Relationship;
    }

    /**
     * Check if this link comes from a custom field.
     */
    public function isFromCustomField(): bool
    {
        return $this->source === EntityLinkSource::CustomField;
    }

    /**
     * Get the appropriate storage strategy for this entity link.
     */
    public function getStorageStrategy(): EntityLinkStorageInterface
    {
        return match ($this->storageType) {
            EntityLinkStorage::ForeignKey => new ForeignKeyStorage,
            EntityLinkStorage::MorphToMany => new MorphToManyStorage,
            EntityLinkStorage::CustomFieldValue => new CustomFieldValueStorage,
        };
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get the entity alias for a model class.
     *
     * @param  class-string<Model>  $modelClass
     */
    private static function getEntityAliasForModel(string $modelClass): string
    {
        return match ($modelClass) {
            Company::class => 'company',
            People::class => 'people',
            Opportunity::class => 'opportunity',
            default => (new $modelClass)->getMorphClass(),
        };
    }

    /**
     * Get only unique identifier matchable fields for Record-type custom fields.
     *
     * For Record-type custom fields, we only allow matching by truly unique
     * identifiers (id, email, domain) - not by name which is not unique.
     *
     * @param  class-string<Model>  $modelClass
     * @return array<MatchableField>
     */
    private static function getUniqueMatchableFieldsForEntity(string $modelClass): array
    {
        return match ($modelClass) {
            Company::class => [
                MatchableField::id(),
                MatchableField::domain('custom_fields_domains'),
            ],
            People::class => [
                MatchableField::id(),
                MatchableField::email('custom_fields_emails'),
            ],
            default => [
                MatchableField::id(),
            ],
        };
    }
}
