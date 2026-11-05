<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Listeners;

use App\Enums\DeletionReason;
use App\Events\WorkspaceDeleted;
use App\Models\User;
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

        $email = User::query()->whereKey($workspace->user_id)->whereNull('scheduled_deletion_at')->value('email');

        if (! is_string($email)) {
            return;
        }

        $name = $workspace->name;

        DB::afterCommit(fn (): mixed => rescue(fn () => Mail::to($email)->queue(new InactiveWorkspaceDeletedMail($name)), report: true));
    }
}
