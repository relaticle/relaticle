<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeamInvitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;

#[Description('Delete team invitations that have been expired for a specified number of days')]
#[Signature('invitations:cleanup
                            {--days=30 : Delete invitations expired more than this many days ago}')]
final class CleanupExpiredInvitationsCommand extends Command
{
    public function handle(): void
    {
        $days = (int) $this->option('days');

        $cutoff = now()->subDays($days);

        $deleted = TeamInvitation::query()
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('expires_at', '<', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('expires_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();

        $this->info("Purged {$deleted} expired invitation(s).");
    }
}
