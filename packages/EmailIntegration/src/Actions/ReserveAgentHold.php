<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Exceptions\AgentOutboxFull;
use Relaticle\EmailIntegration\Models\Email;

final readonly class ReserveAgentHold
{
    public const int WINDOW_HOURS = 24;

    public function execute(User $user, CarbonImmutable $sendsAt, ?Email $moving = null): void
    {
        $windowEnds = now()->addHours(self::WINDOW_HOURS);

        if ($sendsAt->isAfter($windowEnds)) {
            return;
        }

        // Callers queue inside the same transaction, so the lock holds until the email exists.
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        $limit = Config::integer('email-integration.outbox.agent_max_held_per_user');

        $due = Email::query()
            ->where('user_id', $user->getKey())
            ->where('status', EmailStatus::QUEUED)
            ->createdOverMcp()
            ->where(fn (Builder $query): Builder => $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', $windowEnds))
            ->when($moving, fn (Builder $query, Email $email): Builder => $query->whereKeyNot($email->getKey()));

        if ($due->count() < $limit) {
            return;
        }

        $earliest = $due->min('scheduled_for');
        $nextSendsAt = $earliest === null ? now() : Date::parse($earliest)->max(now());

        throw $moving instanceof Email
            ? AgentOutboxFull::forMove($limit, $nextSendsAt, $moving->scheduled_for)
            : AgentOutboxFull::atLimit($limit, $nextSendsAt);
    }
}
