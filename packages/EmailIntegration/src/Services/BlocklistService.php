<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Relaticle\EmailIntegration\Models\Email;

final readonly class BlocklistService
{
    public function __construct(private EmailVisibilityService $visibility) {}

    /**
     * Check if an email should be hidden from the owner's view
     * (mailbox-only blocklist or workspace Blocked entries).
     */
    public function isBlockedForOwner(Email $email): bool
    {
        return $this->visibility->isHiddenFromOwner($email);
    }
}
