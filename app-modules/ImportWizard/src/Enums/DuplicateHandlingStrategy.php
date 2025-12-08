<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum DuplicateHandlingStrategy: string implements HasDescription, HasLabel
{
    /**
     * Skip the row if a duplicate is found.
     * The existing record will not be modified.
     */
    case SKIP = 'skip';

    /**
     * Update the existing record with the imported data.
     * Fields from the CSV will overwrite existing values.
     */
    case UPDATE = 'update';

    /**
     * Create a new record regardless of duplicates.
     * This may result in duplicate entries.
     */
    case CREATE_NEW = 'create_new';

    public function getLabel(): string
    {
        return match ($this) {
            self::SKIP => 'Skip duplicates',
            self::UPDATE => 'Update existing records',
            self::CREATE_NEW => 'Create new records anyway',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SKIP => 'If a matching record exists, skip it and do not import',
            self::UPDATE => 'If a matching record exists, update it with the imported data',
            self::CREATE_NEW => 'Always create a new record, even if duplicates exist',
        };
    }
}
