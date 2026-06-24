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
     * Record that $user has read the email. Read state is per-viewer: the owner's
     * provider-synced state and each teammate's in-app reads are tracked separately,
     * so marking read decrements only the acting user's unread count.
     */
    public function execute(string $emailId, User $user): void
    {
        // Only let a user mark an email they can actually see — guards against
        // forging read rows for private/out-of-team emails via a crafted id.
        $isVisible = Email::query()
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->whereKey($emailId)
            ->exists();

        if (! $isVisible) {
            return;
        }

        EmailRead::query()->firstOrCreate(
            ['email_id' => $emailId, 'user_id' => $user->getKey()],
            ['read_at' => now()],
        );
    }
}
