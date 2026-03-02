<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Models\People;
use App\Models\User;

final readonly class DeletePeople
{
    public function execute(User $user, People $people): void
    {
        abort_unless($user->can('delete', $people), 403);

        $people->delete();
    }
}
