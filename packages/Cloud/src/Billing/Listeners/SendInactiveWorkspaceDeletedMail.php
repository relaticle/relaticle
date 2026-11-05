<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Listeners;

use App\Enums\DeletionReason;
use App\Events\WorkspaceDeleted;
use App\Models\User;
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

        $email = User::query()->whereKey($workspace->user_id)->value('email');

        if (! is_string($email)) {
            return;
        }

        Mail::to($email)->queue((new InactiveWorkspaceDeletedMail($workspace->name))->afterCommit());
    }
}
