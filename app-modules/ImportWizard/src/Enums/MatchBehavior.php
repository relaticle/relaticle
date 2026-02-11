<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

/**
 * Defines special matching behavior during import resolution.
 *
 * Fields without a MatchBehavior use the default: find existing or create new.
 *
 * - AlwaysCreate: Never look up existing records; always create new (e.g. name).
 * - UpdateOnly: Only update existing records; skip if no match found (e.g. Record ID).
 */
enum MatchBehavior: string
{
    case AlwaysCreate = 'always_create';
    case UpdateOnly = 'update_only';
}
