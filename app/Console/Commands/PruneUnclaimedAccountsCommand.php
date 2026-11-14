<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Jetstream\DeleteUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Delete unverified accounts that hold nothing and are older than a specified number of days')]
#[Signature('users:prune-unclaimed
                            {--days=30 : Delete accounts created more than this many days ago}')]
final class PruneUnclaimedAccountsCommand extends Command
{
    private const int CHUNK_SIZE = 100;

    public function handle(DeleteUser $deleteUser): void
    {
        $pruned = 0;

        User::query()
            ->unclaimed()
            ->where('created_at', '<', now()->subDays((int) $this->option('days')))
            ->eachById(function (User $user) use ($deleteUser, &$pruned): void {
                $deleteUser->delete($user);
                $pruned++;
            }, self::CHUNK_SIZE);

        $this->info("Pruned {$pruned} unclaimed account(s).");
    }
}
