<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Defines a matching method for relationship fields.
 *
 * Each matcher represents a way to link imported records to existing records
 * (e.g., by ID, domain, email, or name with auto-creation).
 */
final class RelationshipMatcher extends Data
{
    /**
     * @param  string  $key  Internal key (e.g., 'id', 'domain', 'name')
     * @param  string  $label  Display label (e.g., 'Record ID', 'Domain')
     * @param  string  $description  Explanation of how matching works
     * @param  bool  $createsNew  Whether this matcher creates new records if not found
     * @param  string|null  $hint  Additional hint shown in mapping UI
     * @param  array<string>  $guesses  CSV header guesses for auto-mapping
     * @param  array<string>  $rules  Validation rules for the CSV value
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public bool $createsNew = false,
        public ?string $hint = null,
        public array $guesses = [],
        public array $rules = [],
    ) {}

    /**
     * Create a DataCollection from an array of matchers.
     *
     * @param  array<RelationshipMatcher>  $matchers
     * @return DataCollection<int, RelationshipMatcher>
     */
    public static function collection(array $matchers): DataCollection
    {
        return new DataCollection(self::class, $matchers);
    }
}
