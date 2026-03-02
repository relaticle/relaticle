<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Models\Note;
use App\Models\User;

final readonly class DeleteNote
{
    public function execute(User $user, Note $note): void
    {
        abort_unless($user->can('delete', $note), 403);

        $note->delete();
    }
}
