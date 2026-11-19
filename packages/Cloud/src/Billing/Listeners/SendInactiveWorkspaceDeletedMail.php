<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Listeners;

use App\Enums\DeletionReason;
use App\Events\WorkspaceDeleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletedMail;

final readonly class SendInactiveWorkspaceDeletedMail
{
    public function handle(WorkspaceDeleted $event): void
    {
        $workspace = $event->workspace;

        if ($workspace->deletion_reason !== DeletionReason::Inactivity) {
            return;
        }

        $owner = $workspace->owner()->whereNull('scheduled_deletion_at')->first(['id', 'email', 'locale']);

        if ($owner === null) {
            return;
        }

        $name = $workspace->name;

        DB::afterCommit(fn (): mixed => rescue(fn () => Mail::to($owner)->queue(new InactiveWorkspaceDeletedMail($name)), report: true));
    }
}
