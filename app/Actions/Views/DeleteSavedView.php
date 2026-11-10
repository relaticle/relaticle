<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Notifications\SavedViewChangedNotification;

final readonly class DeleteSavedView
{
    public function execute(User $user, SavedView $view): bool
    {
        abort_unless($user->can('delete', $view), 403);

        $deleted = SavedView::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereKey($view->getKey())
            ->delete() === 1;

        if ($deleted) {
            $view->user->notify(SavedViewChangedNotification::deleted($view, $user));
        }

        return $deleted;
    }
}
