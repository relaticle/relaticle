<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Enums\EmailGrant;
use App\Enums\WorkspaceCapability;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken as PassportAccessToken;
use Laravel\Passport\Passport;

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

        // MCP clients can only request `mcp:use`, the one scope laravel/mcp publishes, and it
        // authorizes the whole toolset. The REST scopes are not honoured here.
        if ($token instanceof PassportAccessToken && ! $token->can(Registrar::OAUTH_SCOPE)) {
            return Response::error('Invalid ability provided.');
        }

        if ($token instanceof PersonalAccessToken && $token->getKey() && ! $token->can($ability)) {
            return Response::error('Invalid ability provided.');
        }

        return null;
    }

    protected function denyIfTokenLacks(EmailGrant $grant): ?Response
    {
        if ($this->holdsAnyEmailGrant($grant)) {
            return null;
        }

        return Response::error('This connection has no email access. Reconnect and allow it on the consent screen, or add the permission to the access token.');
    }

    protected function holdsAnyEmailGrant(EmailGrant ...$grants): bool
    {
        $held = $this->heldEmailGrants();

        return array_any($grants, fn (EmailGrant $grant): bool => in_array($grant, $held, true));
    }

    protected function connectionName(): string
    {
        $token = $this->currentToken();

        $name = match (true) {
            $token instanceof PassportAccessToken => Passport::client()->newQuery()->whereKey($token->oauth_client_id)->value('name'),
            $token instanceof PersonalAccessToken => $token->name,
            default => null,
        };

        return is_string($name) && $name !== '' ? $name : __('mcp.connection.fallback_name');
    }

    /** @return list<string> */
    protected function heldAbilities(): array
    {
        $token = $this->currentToken();

        $emailAbilities = array_column($this->heldEmailGrants(), 'value');

        if ($token instanceof PassportAccessToken) {
            return $token->can(Registrar::OAUTH_SCOPE)
                ? $this->abilitiesTheRoleGrants([...WorkspaceCapability::tokenPermissions(WorkspaceCapability::forOwner()), ...$emailAbilities])
                : [];
        }

        if ($token instanceof PersonalAccessToken && $token->getKey()) {
            $otherAbilities = array_filter(
                $token->abilities ?? [],
                fn (string $ability): bool => EmailGrant::tryFrom($ability) === null,
            );

            return $this->abilitiesTheRoleGrants([...array_values($otherAbilities), ...$emailAbilities]);
        }

        return ['*'];
    }

    /** @return list<EmailGrant> */
    protected function heldEmailGrants(): array
    {
        $token = $this->currentToken();

        return match (true) {
            $token instanceof PassportAccessToken => $token->can(Registrar::OAUTH_SCOPE)
                ? EmailGrant::fromValues((array) $token->oauth_scopes)
                : [],
            $token instanceof PersonalAccessToken && (bool) $token->getKey() => EmailGrant::fromValues((array) $token->abilities),
            default => EmailGrant::offered(),
        };
    }

    private function currentToken(): ?object
    {
        return auth()->user()?->currentAccessToken();
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    private function abilitiesTheRoleGrants(array $abilities): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return array_values(array_intersect(
            $abilities,
            [...$user->grantableTokenPermissions($user->currentWorkspace?->getKey()), '*'],
        ));
    }
}
