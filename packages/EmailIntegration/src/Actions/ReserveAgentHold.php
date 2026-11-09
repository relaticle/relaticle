<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Exceptions\AgentOutboxFull;
use Relaticle\EmailIntegration\Models\Email;

final readonly class ReserveAgentHold
{
    public function execute(User $user): void
    {
        // Callers queue inside the same transaction, so the lock holds until the email exists.
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        $limit = Config::integer('email-integration.outbox.agent_max_held_per_user');

        $held = Email::query()
            ->where('user_id', $user->getKey())
            ->where('status', EmailStatus::QUEUED)
            ->createdOverMcp()
            ->count();

        throw_if($held >= $limit, AgentOutboxFull::atLimit($limit));
    }
}
