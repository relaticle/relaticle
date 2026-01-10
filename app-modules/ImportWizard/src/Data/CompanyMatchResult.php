<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Spatie\LaravelData\Data;

/**
 * Result of company matching during import preview.
 *
 * Match types:
 * - 'id': Matched via company ID (highest priority)
 * - 'domain': Matched via email domain → company domains custom field
 * - 'new': Will create new company (company_name provided)
 * - 'none': No company data to link/create (company_name empty, no matches)
 */
final class CompanyMatchResult extends Data
{
    public function __construct(
        public readonly string $companyName,
        public readonly string $matchType,
        public readonly int $matchCount,
        public readonly ?string $companyId = null,
    ) {}

    public function isDomainMatch(): bool
    {
        return $this->matchType === 'domain';
    }
}
