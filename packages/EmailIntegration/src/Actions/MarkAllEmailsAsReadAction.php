<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class MarkAllEmailsAsReadAction
{
    /**
     * Mark every visible, still-unread inbound email in the given folder as read
     * for $user. Read state is per-viewer, so this clears only the acting user's
     * unread count. Returns the number of emails newly marked read.
     *
     * Unread is meaningful only for inbound mail, so Sent (and other outbound-only
     * folders) resolve to zero matches and the action is a no-op there.
     */
    public function execute(User $user, EmailFolder $folder): int
    {
        $query = Email::query()
            ->forTeam($user->current_team_id)
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->unreadFor($user->getKey());

        if ($folder === EmailFolder::Sent) {
            $query->sent();
        } elseif ($folder === EmailFolder::Inbox) {
            $query->inbox();
        }

        $emailIds = $query->pluck('id');

        if ($emailIds->isEmpty()) {
            return 0;
        }

        $now = now();

        // Every matched id is guaranteed absent from email_reads (unreadFor filters
        // on that), so a single bulk insert is safe — no conflict handling needed.
        EmailRead::query()->insert($emailIds->map(fn (string $emailId): array => [
            'id' => (string) Str::ulid(),
            'email_id' => $emailId,
            'user_id' => $user->getKey(),
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return $emailIds->count();
    }
}
