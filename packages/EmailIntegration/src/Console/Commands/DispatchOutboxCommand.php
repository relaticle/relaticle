<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use App\Jobs\SendEmailJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

#[Description('Release due queued emails subject to per-account rate limits.')]
#[Signature('email:dispatch-outbox')]
final class DispatchOutboxCommand extends Command
{
    public function handle(): int
    {
        $defaultHourly = Config::integer('email-integration.outbox.defaults.hourly_send_limit');
        $defaultDaily = Config::integer('email-integration.outbox.defaults.daily_send_limit');

        ConnectedAccount::query()
            ->where('status', EmailAccountStatus::ACTIVE)
            ->whereHas('outgoingEmails', fn (Builder $emailQuery): Builder => $emailQuery
                ->where('status', EmailStatus::QUEUED)
                ->where(fn (Builder $dueQuery): Builder => $dueQuery->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))
            )
            ->each(function (ConnectedAccount $account) use ($defaultHourly, $defaultDaily): void {
                $this->dispatchForAccount($account, $defaultHourly, $defaultDaily);
            });

        return self::SUCCESS;
    }

    private function dispatchForAccount(ConnectedAccount $account, int $defaultHourly, int $defaultDaily): void
    {
        $hourlyLimit = $account->hourly_send_limit ?? $defaultHourly;
        $dailyLimit = $account->daily_send_limit ?? $defaultDaily;

        $hourlySent = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->where('direction', EmailDirection::OUTBOUND)
            ->where('status', EmailStatus::SENT)
            ->where('sent_at', '>=', now()->subHour())
            ->count();

        // Rolling 24h window; uses an absolute instant so it is timezone-agnostic against the UTC sent_at column.
        $dailySent = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->where('direction', EmailDirection::OUTBOUND)
            ->where('status', EmailStatus::SENT)
            ->where('sent_at', '>=', now()->subDay())
            ->count();

        // Emails already claimed (SENDING) by this or a previous run are in flight and
        // consume capacity even though they have not reached SENT yet. Counting them
        // prevents overlapping dispatch runs from collectively overshooting the limits.
        $inFlight = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->where('direction', EmailDirection::OUTBOUND)
            ->where('status', EmailStatus::SENDING)
            ->count();

        $capacity = max(0, min(
            $hourlyLimit - $hourlySent - $inFlight,
            $dailyLimit - $dailySent - $inFlight,
        ));

        if ($capacity === 0) {
            return;
        }

        /** @var Collection<int, Email> $due */
        $due = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->where('status', EmailStatus::QUEUED)
            ->where(fn (Builder $dueQuery): Builder => $dueQuery->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))
            ->orderByRaw("CASE priority WHEN 'priority' THEN 0 ELSE 1 END")
            ->orderByRaw('scheduled_for IS NULL DESC')
            ->orderBy('scheduled_for')
            ->oldest()
            ->limit($capacity)
            ->get();

        foreach ($due as $email) {
            $claimed = Email::query()
                ->whereKey($email->getKey())
                ->where('status', EmailStatus::QUEUED)
                ->update(['status' => EmailStatus::SENDING]);

            if ($claimed === 0) {
                continue;
            }

            $queueName = ($email->priority ?? EmailPriority::BULK)->queueName();
            dispatch(new SendEmailJob($email->getKey()))->onQueue($queueName);
        }
    }
}
