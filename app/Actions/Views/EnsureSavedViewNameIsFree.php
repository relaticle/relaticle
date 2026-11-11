<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class EnsureSavedViewNameIsFree
{
    public function execute(User $actor, SavedView $view, string $name): string
    {
        $name = trim($name);
        $taken = SavedView::query()
            ->named($actor, $view->workspace_id, $view->entity, $name)
            ->when($view->exists, fn (Builder $query): Builder => $query->whereKeyNot($view->getKey()))
            ->exists();

        if ($name === '' || $taken) {
            throw ValidationException::withMessages([
                'name' => $name === '' ? __('filament/panel.tables.views.name_required') : __('filament/panel.tables.views.name_taken'),
            ]);
        }

        return $name;
    }
}
