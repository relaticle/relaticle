<?php

declare(strict_types=1);

namespace App\Actions\TableLayouts;

use App\Models\TableLayout;
use App\Models\User;
use App\Notifications\TableLayoutChangedNotification;

final readonly class DeleteTableLayout
{
    public function execute(User $user, TableLayout $layout): void
    {
        abort_unless($user->can('delete', $layout), 403);

        $layout->delete();

        $layout->user->notify(new TableLayoutChangedNotification($layout->workspace, $user, $layout->name, $layout->entity, TableLayoutChangedNotification::DELETED));
    }
}
