<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
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
     *
     * When $record is given (a Company/People/Opportunity record page), only that
     * record's emails are marked — not the user's whole team inbox.
     */
    public function execute(User $user, EmailFolder $folder, Company|Opportunity|People|null $record = null): int
    {
        $query = ($record?->emails() ?? Email::query()->forTeam($user->current_team_id))
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->unreadFor($user->getKey());

        if ($folder === EmailFolder::Sent) {
            $query->sent();
        } elseif ($folder === EmailFolder::Inbox) {
            $query->inbox();
        }

        // Qualify the column — the record-scoped path joins through `emailables`,
        // where a bare "id" is ambiguous.
        $emailIds = $query->pluck('emails.id');

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
