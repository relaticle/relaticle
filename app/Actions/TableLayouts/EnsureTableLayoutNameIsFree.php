<?php

declare(strict_types=1);

namespace App\Actions\TableLayouts;

use App\Models\TableLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class EnsureTableLayoutNameIsFree
{
    public function execute(User $actor, TableLayout $layout, string $name): string
    {
        $name = trim($name);
        $owner = $layout->user_id === $actor->getKey() ? $actor : $layout->user;

        $taken = TableLayout::query()
            ->named($owner, $layout->workspace_id, $layout->entity, $name)
            ->when($layout->exists, fn (Builder $query): Builder => $query->whereKeyNot($layout->getKey()))
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
