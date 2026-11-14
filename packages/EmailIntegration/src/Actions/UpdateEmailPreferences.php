<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\UndoSendWindow;

final readonly class UpdateEmailPreferences
{
    public function execute(User $user, UndoSendWindow $undo): void
    {
        $user->update(['email_undo_seconds' => $undo->value]);
    }
}
