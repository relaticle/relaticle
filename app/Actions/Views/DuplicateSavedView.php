<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final readonly class DuplicateSavedView
{
    private const int ATTEMPTS = 2;

    public function execute(User $user, SavedView $view): SavedView
    {
        abort_unless($user->can('view', $view), 403);

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $copy = new SavedView([
                'workspace_id' => $view->workspace_id,
                'user_id' => $user->getKey(),
                'entity' => $view->entity,
                'type' => $view->type,
                'name' => $this->freeName($user, $view),
                'state' => $view->state,
            ]);

            try {
                $copy->save();

                return $copy;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw ValidationException::withMessages(['name' => __('filament/panel.tables.views.name_taken')]);
    }

    private function freeName(User $user, SavedView $view): string
    {
        $taken = SavedView::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $view->workspace_id)
            ->where('entity', $view->entity)
            ->whereBelongsTo($user)
            ->pluck('name')
            ->map(fn (string $name): string => mb_strtolower($name))
            ->flip();

        $base = __('filament/panel.tables.views.copy_name', ['name' => $view->name]);

        for ($number = 1; ; $number++) {
            $candidate = $number === 1 ? $base : "{$base} {$number}";

            if (! $taken->has(mb_strtolower($candidate))) {
                return $candidate;
            }
        }
    }
}
