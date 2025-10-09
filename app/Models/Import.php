<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTeam;
use Filament\Actions\Imports\Models\Import as BaseImport;

final class Import extends BaseImport
{
    use HasTeam;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        self::creating(function (Import $import): void {
            if (auth()->check()) {
                /** @var \App\Models\User $user */
                $user = auth()->user();
                $import->team_id = $user->currentTeam?->getKey();
            }
        });
    }
}
