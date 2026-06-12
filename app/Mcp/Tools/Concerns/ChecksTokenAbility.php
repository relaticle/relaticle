<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\PersonalAccessToken;
use Laravel\Mcp\Response;
use Laravel\Passport\AccessToken as PassportAccessToken;

trait ChecksTokenAbility
{
    /**
     * Return a structured MCP error response when the current token lacks the
     * requested ability; null when the call is allowed.
     *
     * Sanctum's MissingAbilityException is no longer caught by laravel/mcp
     * since v0.6.5 (Server.php only catches JsonRpcException and ValidationException),
     * so we return the error inline instead of throwing.
     *
     * currentAccessToken() returns null when:
     *   1. The request authenticated via $this->actingAs($user) in tests (no token).
     *   2. The request authenticated via session/web guard (no API token).
     * Both are acceptable bypasses: feature tests rely on (1), and the MCP route
     * is only reachable via the auth:sanctum,api guard, which never produces case (2).
     */
    protected function denyIfTokenCannot(string $ability): ?Response
    {
        $user = auth()->user();

        /** @var PersonalAccessToken|PassportAccessToken|object|null $token */
        $token = $user?->currentAccessToken();

        if ($token instanceof PassportAccessToken && ! $token->can($ability)) {
            return Response::error('Invalid ability provided.');
        }

        if ($token instanceof PersonalAccessToken && $token->getKey() && ! $token->can($ability)) {
            return Response::error('Invalid ability provided.');
        }

        return null;
    }
}
