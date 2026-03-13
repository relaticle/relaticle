<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Actions\Exports\Models\Export as FilamentExport;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Export extends FilamentExport
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasUlids;
}
