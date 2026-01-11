<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\MatchType;
use Spatie\LaravelData\Data;

/**
 * Result of company matching during import preview.
 */
final class CompanyMatchResult extends Data
{
    public function __construct(
        public readonly string $companyName,
        public readonly MatchType $matchType,
        public readonly int $matchCount,
        public readonly ?string $companyId = null,
    ) {}

    public function isDomainMatch(): bool
    {
        return $this->matchType === MatchType::Domain;
    }
}
