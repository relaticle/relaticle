<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Enums;

/**
 * Action to take for a matched row during import.
 */
enum RowMatchAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Skip = 'skip';
}
