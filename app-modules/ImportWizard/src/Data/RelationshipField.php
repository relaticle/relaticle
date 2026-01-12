<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Defines a relationship field for import mapping.
 *
 * Relationship fields allow users to link imported records to existing or new
 * records in related entities (e.g., linking People to Companies).
 */
final class RelationshipField extends Data
{
    /**
     * @param  string  $name  Internal field name (e.g., 'company')
     * @param  string  $label  Display label (e.g., 'Company')
     * @param  string  $targetEntity  Target entity type (e.g., 'companies')
     * @param  string  $icon  Heroicon name for UI
     * @param  DataCollection<int, RelationshipMatcher>  $matchers  Available matching methods
     * @param  string|null  $defaultMatcher  Default matcher key
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $targetEntity,
        public string $icon,
        #[DataCollectionOf(RelationshipMatcher::class)]
        public DataCollection $matchers,
        public ?string $defaultMatcher = null,
    ) {}

    /**
     * Get a matcher by key.
     */
    public function getMatcher(string $key): ?RelationshipMatcher
    {
        return $this->matchers->first(fn (RelationshipMatcher $m): bool => $m->key === $key);
    }

    /**
     * Get the default matcher or first available.
     */
    public function getDefaultMatcher(): RelationshipMatcher
    {
        if ($this->defaultMatcher !== null) {
            $matcher = $this->getMatcher($this->defaultMatcher);
            if ($matcher instanceof \Relaticle\ImportWizard\Data\RelationshipMatcher) {
                return $matcher;
            }
        }

        return $this->matchers->first();
    }

    /**
     * Standard company relationship field with ID, domain, and name matchers.
     *
     * Note: Automatic domain matching from person's emails also happens
     * invisibly before falling back to name-based creation.
     */
    public static function company(): self
    {
        return new self(
            name: 'company',
            label: 'Company',
            targetEntity: 'companies',
            icon: 'heroicon-o-building-office-2',
            matchers: RelationshipMatcher::collection([
                new RelationshipMatcher(
                    key: 'id',
                    label: 'Record ID',
                    description: 'Match company by exact ULID',
                    createsNew: false,
                    hint: 'Use when you have exported company IDs',
                    guesses: ['company_id', 'company_record_id'],
                    rules: ['nullable', 'string', 'ulid'],
                ),
                new RelationshipMatcher(
                    key: 'domain',
                    label: 'Domain',
                    description: 'Match company by domain',
                    createsNew: false,
                    hint: 'Use when CSV has explicit domain column',
                    guesses: ['company_domain', 'domain'],
                    rules: ['nullable', 'string'],
                ),
                new RelationshipMatcher(
                    key: 'name',
                    label: 'Name',
                    description: 'Create new company with this name',
                    createsNew: true,
                    hint: 'Always creates new (name is not unique)',
                    guesses: ['company_name', 'company', 'employer', 'organization', 'account'],
                    rules: ['nullable', 'string', 'max:255'],
                ),
            ]),
            defaultMatcher: 'name',
        );
    }

    /**
     * Standard contact relationship field with ID, email, and name matchers.
     */
    public static function contact(): self
    {
        return new self(
            name: 'contact',
            label: 'Contact',
            targetEntity: 'people',
            icon: 'heroicon-o-user',
            matchers: RelationshipMatcher::collection([
                new RelationshipMatcher(
                    key: 'id',
                    label: 'Record ID',
                    description: 'Match contact by exact ULID',
                    createsNew: false,
                    hint: 'Use when you have exported people IDs',
                    guesses: ['contact_id', 'person_id', 'people_id'],
                    rules: ['nullable', 'string', 'ulid'],
                ),
                new RelationshipMatcher(
                    key: 'email',
                    label: 'Email',
                    description: 'Match contact by email address',
                    createsNew: false,
                    hint: 'Email is unique - matches existing contacts',
                    guesses: ['contact_email', 'person_email'],
                    rules: ['nullable', 'email'],
                ),
                new RelationshipMatcher(
                    key: 'name',
                    label: 'Name',
                    description: 'Create new person with this name',
                    createsNew: true,
                    hint: 'Always creates new (name is not unique)',
                    guesses: ['contact_name', 'contact', 'person', 'primary_contact'],
                    rules: ['nullable', 'string', 'max:255'],
                ),
            ]),
            defaultMatcher: 'name',
        );
    }
}
