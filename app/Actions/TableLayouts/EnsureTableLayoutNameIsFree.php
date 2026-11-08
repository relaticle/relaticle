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
    public function execute(User $actor, string $workspaceId, CrmEntity $entity, string $name, ?TableLayout $renaming = null): string
    {
        $name = trim($name);
        $owner = $renaming instanceof TableLayout ? $renaming->user : $actor;

        $taken = TableLayout::query()
            ->named($owner, $workspaceId, $entity, $name)
            ->when($renaming, fn (Builder $query): Builder => $query->whereKeyNot($renaming->getKey()))
            ->exists();

        if ($name === '' || $taken) {
            throw ValidationException::withMessages([
                'name' => match (true) {
                    $name === '' => __('filament/panel.tables.layouts.name_required'),
                    $owner->is($actor) => __('filament/panel.tables.layouts.name_taken'),
                    default => __('filament/panel.tables.layouts.name_taken_by_owner', ['owner' => $owner->name]),
                },
            ]);
        }

        return $name;
    }
}
