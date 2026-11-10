<?php

declare(strict_types=1);

namespace App\Actions\TableLayouts;

use App\Models\Scopes\WorkspaceScope;
use App\Models\TableLayout;
use App\Models\User;
use App\Notifications\TableLayoutChangedNotification;

final readonly class DeleteTableLayout
{
    public function execute(User $user, TableLayout $layout): bool
    {
        abort_unless($user->can('delete', $layout), 403);

        $deleted = TableLayout::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereKey($layout->getKey())
            ->delete() === 1;

        if ($deleted) {
            $layout->user->notify(TableLayoutChangedNotification::deleted($layout, $user));
        }

        return $deleted;
    }
}
