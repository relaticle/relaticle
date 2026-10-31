<?php

declare(strict_types=1);

namespace App\Actions\TableLayouts;

use App\Enums\CrmEntity;
use App\Models\TableLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class EnsureTableLayoutNameIsFree
{
    public function execute(User $owner, string $workspaceId, CrmEntity $entity, string $name, ?TableLayout $ignore = null): string
    {
        $name = trim($name);

        $taken = TableLayout::query()
            ->named($owner, $workspaceId, $entity, $name)
            ->when($ignore, fn (Builder $query): Builder => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($name === '' || $taken) {
            throw ValidationException::withMessages([
                'name' => $name === '' ? __('filament/panel.tables.layouts.name_required') : __('filament/panel.tables.layouts.name_taken'),
            ]);
        }

        return $name;
    }
}
