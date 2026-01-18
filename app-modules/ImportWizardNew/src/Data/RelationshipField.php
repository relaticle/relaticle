<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Data;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Defines a relationship field for import mapping.
 *
 * Relationship fields define how imported data connects to related entities.
 * They specify which model to link to, how to match records, and whether
 * new related records can be created.
 */
final class RelationshipField extends Data
{
    /**
     * @param  string  $name  Internal relationship name (e.g., 'company')
     * @param  class-string<Model>  $relatedModel  The related model class
     * @param  string  $type  Relationship type: 'belongsTo' or 'morphToMany'
     * @param  array<MatchableField>  $matchableFields  Fields that can match related records
     * @param  bool  $canCreate  Whether new related records can be created
     * @param  string  $label  Display label
     * @param  string|null  $foreignKey  Foreign key column (for belongsTo)
     * @param  array<string>  $guesses  Column name aliases for auto-mapping
     */
    public function __construct(
        public readonly string $name,
        public readonly string $relatedModel,
        public readonly string $type,
        #[DataCollectionOf(MatchableField::class)]
        public readonly array $matchableFields = [],
        public readonly bool $canCreate = false,
        public readonly string $label = '',
        public readonly ?string $foreignKey = null,
        public readonly array $guesses = [],
    ) {}

    /**
     * Create a belongsTo relationship field.
     *
     * @param  class-string<Model>  $relatedModel
     */
    public static function belongsTo(string $name, string $relatedModel): self
    {
        return new self(
            name: $name,
            relatedModel: $relatedModel,
            type: 'belongsTo',
            label: ucfirst($name),
        );
    }

    /**
     * Create a morphToMany relationship field.
     *
     * @param  class-string<Model>  $relatedModel
     */
    public static function morphToMany(string $name, string $relatedModel): self
    {
        return new self(
            name: $name,
            relatedModel: $relatedModel,
            type: 'morphToMany',
            label: ucfirst($name),
        );
    }

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

    /**
     * Set matchable fields for this relationship.
     *
     * @param  array<MatchableField>  $matchableFields
     */
    public function matchableFields(array $matchableFields): self
    {
        return new self(
            name: $this->name,
            relatedModel: $this->relatedModel,
            type: $this->type,
            matchableFields: $matchableFields,
            canCreate: $this->canCreate,
            label: $this->label,
            foreignKey: $this->foreignKey,
            guesses: $this->guesses,
        );
    }

    /**
     * Set whether new related records can be created.
     */
    public function canCreate(bool $canCreate = true): self
    {
        return new self(
            name: $this->name,
            relatedModel: $this->relatedModel,
            type: $this->type,
            matchableFields: $this->matchableFields,
            canCreate: $canCreate,
            label: $this->label,
            foreignKey: $this->foreignKey,
            guesses: $this->guesses,
        );
    }

    /**
     * Set the display label.
     */
    public function label(string $label): self
    {
        return new self(
            name: $this->name,
            relatedModel: $this->relatedModel,
            type: $this->type,
            matchableFields: $this->matchableFields,
            canCreate: $this->canCreate,
            label: $label,
            foreignKey: $this->foreignKey,
            guesses: $this->guesses,
        );
    }

    /**
     * Set the foreign key column.
     */
    public function foreignKey(string $foreignKey): self
    {
        return new self(
            name: $this->name,
            relatedModel: $this->relatedModel,
            type: $this->type,
            matchableFields: $this->matchableFields,
            canCreate: $this->canCreate,
            label: $this->label,
            foreignKey: $foreignKey,
            guesses: $this->guesses,
        );
    }

    /**
     * Set column name aliases for auto-mapping.
     *
     * @param  array<string>  $guesses
     */
    public function guess(array $guesses): self
    {
        return new self(
            name: $this->name,
            relatedModel: $this->relatedModel,
            type: $this->type,
            matchableFields: $this->matchableFields,
            canCreate: $this->canCreate,
            label: $this->label,
            foreignKey: $this->foreignKey,
            guesses: $guesses,
        );
    }

    /**
     * Check if this is a belongsTo relationship.
     */
    public function isBelongsTo(): bool
    {
        return $this->type === 'belongsTo';
    }

    /**
     * Check if this is a morphToMany relationship.
     */
    public function isMorphToMany(): bool
    {
        return $this->type === 'morphToMany';
    }

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
     * Check if this relationship matches a given column header.
     */
    public function matchesHeader(string $header): bool
    {
        $normalized = strtolower(trim($header));

        if ($normalized === strtolower($this->name)) {
            return true;
        }

        if ($normalized === strtolower($this->label)) {
            return true;
        }

        return array_any($this->guesses, fn ($guess): bool => strtolower($guess) === $normalized);
    }

    /**
     * Get the icon for this relationship based on the related model.
     */
    public function icon(): string
    {
        return match ($this->relatedModel) {
            Company::class => 'heroicon-o-building-office-2',
            People::class => 'heroicon-o-user',
            Opportunity::class => 'heroicon-o-currency-dollar',
            default => 'heroicon-o-link',
        };
    }
}
