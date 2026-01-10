<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Models;

use App\Models\Concerns\HasTeam;
use Filament\Actions\Imports\Models\Import as BaseImport;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class Import extends BaseImport
{
    use HasTeam;

    use HasUlids;
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
}
