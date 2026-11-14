<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Features\EmailConversations;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

trait HasConversationsFlag
{
    protected function conversationsEnabled(?Workspace $workspace): bool
    {
        if (! $workspace instanceof Workspace) {
            return false;
        }

        return Feature::for($workspace)->active(EmailConversations::class);
    }
}
