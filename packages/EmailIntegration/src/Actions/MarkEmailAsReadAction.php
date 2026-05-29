<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Models\Email;

final readonly class MarkEmailAsReadAction
{
    public function execute(string $emailId, User $user): void
    {
        Email::query()
            ->whereKey($emailId)
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
