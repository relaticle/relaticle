<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;

final readonly class ComposedEmailsQuery
{
    public function draft(User $user, string $id): ?Email
    {
        return Email::query()
            ->composedBy($user)
            ->where('status', EmailStatus::DRAFT)
            ->whereKey($id)
            ->first();
    }

    public function outbox(User $user, string $id): ?Email
    {
        return Email::query()
            ->composedBy($user)
            ->whereNot('status', EmailStatus::DRAFT)
            ->whereKey($id)
            ->first();
    }
}
