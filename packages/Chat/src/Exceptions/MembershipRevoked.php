<?php

declare(strict_types=1);

namespace Relaticle\Chat\Exceptions;

use RuntimeException;

final class MembershipRevoked extends RuntimeException
{
    public static function refusal(): string
    {
        return __('You are no longer a member of this workspace, so the assistant stopped.');
    }

    public static function forWorkspace(string $workspaceId): self
    {
        return new self("User no longer belongs to workspace [{$workspaceId}].");
    }
}
