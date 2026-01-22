<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

/**
 * Action to take for a matched row during import.
 */
enum RowMatchAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Skip = 'skip';
}
