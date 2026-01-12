<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\MatchType;
use Spatie\LaravelData\Data;

/**
 * Result of relationship matching during import preview.
 */
final class RelationshipMatchResult extends Data
{
    public function __construct(
        public readonly string $relationshipName,
        public readonly string $displayName,
        public readonly MatchType $matchType,
        public readonly string $matcherUsed,
        public readonly ?string $matchedRecordId = null,
        public readonly ?string $matchedRecordName = null,
        public readonly string $icon = 'heroicon-o-link',
    ) {}

    public function willCreate(): bool
    {
        return $this->matchType->willCreate();
    }
}
