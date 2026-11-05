<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class MarkEmailAsReadAction
{
    /**
     * Record that $user has read the emails. Read state is per-viewer: the owner's
     * provider-synced state and each teammate's in-app reads are tracked separately,
     * so marking read decrements only the acting user's unread count.
     *
     * @param  list<string>  $emailIds
     */
    public function execute(array $emailIds, User $user): void
    {
        if ($emailIds === []) {
            return;
        }

        // Only let a user mark an email they can actually see. Guards against
        // forging read rows for private/out-of-workspace emails via a crafted id.
        $visibleIds = Email::query()
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->whereKey($emailIds)
            ->pluck('emails.id');

        $now = now();

        EmailRead::query()->insertOrIgnore($visibleIds->map(fn (string $emailId): array => [
            'id' => (new EmailRead)->newUniqueId(),
            'email_id' => $emailId,
            'user_id' => $user->getKey(),
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }
}
