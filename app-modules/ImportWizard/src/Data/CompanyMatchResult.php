<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Spatie\LaravelData\Data;

/**
 * Result of company matching during import preview.
 *
 * Match types (Attio-style priority):
 * - 'id': Matched via company ID (highest priority)
 * - 'domain': Matched via email domain → company domains custom field
 * - 'new': Will create new company (company_name provided)
 * - 'none': No company data to link/create (company_name empty, no matches)
 */
final class CompanyMatchResult extends Data
{
    public function __construct(
        public string $companyName,
        public string $matchType,
        public int $matchCount,
        public ?string $companyId = null,
    ) {}

    public function isIdMatch(): bool
    {
        return $this->matchType === 'id';
    }

    public function isDomainMatch(): bool
    {
        return $this->matchType === 'domain';
    }

    public function isNew(): bool
    {
        return $this->matchType === 'new';
    }

    public function isNone(): bool
    {
        return $this->matchType === 'none';
    }

    /**
     * @deprecated Name matching removed - use ID or domain matching only
     */
    public function isNameMatch(): bool
    {
        return $this->matchType === 'name';
    }

    /**
     * @deprecated Ambiguous handling simplified - returns 'new' instead
     */
    public function isAmbiguous(): bool
    {
        return $this->matchType === 'ambiguous';
    }
}
