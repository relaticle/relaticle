<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Models;

use App\Models\Concerns\HasTeam;
use Filament\Actions\Exports\Models\Export as BaseExport;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class Export extends BaseExport
{
    use HasTeam;
    use HasUlids;

    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
}
