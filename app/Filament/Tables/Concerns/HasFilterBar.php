<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Models\User;
use Filament\Facades\Filament;

trait HasFilterBar
{
    /** @var array<string, string> */
    private array $nativeColumnLabels = [];

    /**
     * @return array<string, FilterField>
     */
    public function filterFieldCatalog(): array
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? FilterFieldCatalog::for($this->tableEntity(), $user, $this->nativeColumnLabels)
            : [];
    }
}
