<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use Filament\Actions\Imports\Importer;

abstract class BaseImporter extends Importer
{
    // Base class for all importers in the application
    // Team ID is automatically set by ImportObserver on the Import model
}
