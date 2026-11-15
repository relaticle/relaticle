<?php

declare(strict_types=1);

namespace Relaticle\Chat\Exceptions;

use RuntimeException;

final class MembershipRevokedException extends RuntimeException
{
    public static function forWorkspace(string $workspaceId): self
    {
        return new self("User no longer belongs to workspace [{$workspaceId}].");
    }
}
