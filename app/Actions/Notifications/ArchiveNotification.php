<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\Notification;
use App\Models\User;

final readonly class ArchiveNotification
{
    public function execute(User $user, Notification $row): void
    {
        abort_unless(
            $row->getAttribute('notifiable_type') === $user->getMorphClass() && (string) $row->getAttribute('notifiable_id') === (string) $user->getKey(),
            403,
        );

        abort_unless($row->workspace_id === null || $user->belongsToWorkspaceId($row->workspace_id), 403);

        $row->forceFill([
            'archived_at' => now(),
            'read_at' => $row->read_at ?? now(),
        ])->save();
    }
}
