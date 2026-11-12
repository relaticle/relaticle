<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Data\TableStateData;
use App\Enums\SavedViewType;
use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateSavedView
{
    public function __construct(private EnsureSavedViewNameIsFree $ensureNameIsFree) {}

    /**
     * @param  array{name?: string, state?: TableStateData|BoardStateData}  $attributes
     */
    public function execute(User $user, SavedView $view, array $attributes): SavedView
    {
        abort_unless($user->can('update', $view), 403);

        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = $this->ensureNameIsFree->execute($user, $view, $attributes['name']);
        }

        if (array_key_exists('state', $attributes)) {
            $state = $attributes['state'];

            abort_unless($state instanceof BoardStateData === ($view->type === SavedViewType::Board), 422);

            $attributes['state'] = $state instanceof TableStateData ? $state->withoutWidths() : $state;
        }

        return DB::transaction(function () use ($view, $attributes): SavedView {
            SavedView::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($view->getKey())->lockForUpdate()->firstOrFail();

            try {
                $view->update($attributes);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('filament/panel.tables.views.name_taken')]);
            }

            return $view;
        });
    }
}
