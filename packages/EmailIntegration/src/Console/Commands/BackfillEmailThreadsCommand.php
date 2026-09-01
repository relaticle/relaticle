<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\EmailIntegration\Actions\SyncEmailThreadAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailThread;

#[Description('Rebuild EmailThread aggregates for already-synced emails.')]
#[Signature('email:backfill-threads')]
final class BackfillEmailThreadsCommand extends Command
{
    public function handle(SyncEmailThreadAction $syncThread): int
    {
        $accounts = ConnectedAccount::query()->get()->keyBy(fn (ConnectedAccount $account): string => (string) $account->getKey());

        $rebuilt = 0;

        Email::query()
            ->select('connected_account_id', 'thread_id')
            ->whereNotNull('thread_id')
            ->distinct()
            ->orderBy('connected_account_id')
            ->orderBy('thread_id')
            ->cursor()
            ->each(function (Email $email) use ($accounts, $syncThread, &$rebuilt): void {
                $account = $accounts->get((string) $email->connected_account_id);

                if ($account === null) {
                    return;
                }

                if ($syncThread->execute($account, $email->thread_id) instanceof EmailThread) {
                    $rebuilt++;
                }
            });

        $this->info("Rebuilt {$rebuilt} email thread(s).");

        return self::SUCCESS;
    }
}
