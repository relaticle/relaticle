<?php

declare(strict_types=1);

namespace App\Features;

use App\Models\Workspace;

final readonly class EmailConversations
{
    public function resolve(Workspace $workspace): bool
    {
        if (! config('relaticle.features.email_integration', false)) {
            return false;
        }

        $workspaces = (array) config('relaticle.features.email_conversations', []);

        return in_array('*', $workspaces, true) || in_array($workspace->getKey(), $workspaces, true);
    }
}
