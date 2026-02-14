<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Actions\Exports\Models\Export as FilamentExport;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class Export extends FilamentExport
{
    use HasUlids;
}
