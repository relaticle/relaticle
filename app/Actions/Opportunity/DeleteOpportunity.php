<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Models\Opportunity;
use App\Models\User;

final readonly class DeleteOpportunity
{
    public function execute(User $user, Opportunity $opportunity): void
    {
        abort_unless($user->can('delete', $opportunity), 403);

        $opportunity->delete();
    }
}
