<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use App\Jobs\SendEmailJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

final class DispatchOutboxCommand extends Command
{
    protected $signature = 'email:dispatch-outbox';

    protected $description = 'Release due queued emails subject to per-account rate limits.';

    public function handle(): int
    {
        $defaultHourly = Config::integer('email-integration.outbox.defaults.hourly_send_limit');
        $defaultDaily = Config::integer('email-integration.outbox.defaults.daily_send_limit');

        ConnectedAccount::query()
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

        $dailySent = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->where('direction', EmailDirection::OUTBOUND)
            ->where('status', EmailStatus::SENT)
            ->where('sent_at', '>=', today())
            ->count();

        $capacity = max(0, min($hourlyLimit - $hourlySent, $dailyLimit - $dailySent));

        if ($capacity === 0) {
            return;
        }

        /** @var Collection<int, Email> $due */
        $due = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->where('status', EmailStatus::QUEUED)
            ->where(fn (Builder $dueQuery): Builder => $dueQuery->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))
            ->orderByRaw("CASE priority WHEN 'priority' THEN 0 ELSE 1 END")
            ->orderByRaw('scheduled_for NULLS FIRST')->oldest()
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
