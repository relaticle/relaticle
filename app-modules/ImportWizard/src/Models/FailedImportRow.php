<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Models;

use Filament\Actions\Imports\Models\FailedImportRow as BaseFailedImportRow;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class FailedImportRow extends BaseFailedImportRow
{
    use HasUlids;
}
