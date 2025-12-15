<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Models;

use App\Models\Concerns\HasTeam;
use App\Models\User;
use Filament\Actions\Exports\Models\Export as BaseExport;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class Export extends BaseExport
{
    use HasTeam;
    use HasUlids;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        self::creating(function (Export $export): void {
            if (auth()->check()) {
                /** @var User $user */
                $user = auth()->user();
                $export->team_id = $user->currentTeam?->getKey();
            }
        });
    }
}
