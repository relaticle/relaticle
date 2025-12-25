<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Spatie\LaravelData\Data;

/**
 * Result of company matching during import preview.
 *
 * Match types:
 * - 'domain': Matched via email domain → company domains custom field
 * - 'name': Matched via exact company name
 * - 'ambiguous': Multiple companies matched (by domain or name)
 * - 'new': No match found, will create new company
 */
final class CompanyMatchResult extends Data
{
    public function __construct(
        public string $companyName,
        public string $matchType,
        public int $matchCount,
        public ?string $companyId = null,
    ) {}

    public function isAmbiguous(): bool
    {
        return $this->matchType === 'ambiguous';
    }

    public function isNew(): bool
    {
        return $this->matchType === 'new';
    }

    public function isDomainMatch(): bool
    {
        return $this->matchType === 'domain';
    }

    public function isNameMatch(): bool
    {
        return $this->matchType === 'name';
    }
}
