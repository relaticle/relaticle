<?php

declare(strict_types=1);

namespace App\Support\Passport;

use App\Models\Workspace;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use SensitiveParameter;

final class WorkspaceBearerTokenResponse extends BearerTokenResponse
{
    /** @return array{scope: string, workspace?: array{id: string, name: string}} */
    protected function getExtraParams(#[SensitiveParameter] AccessTokenEntityInterface $accessToken): array
    {
        return [
            'scope' => implode(' ', array_map(
                fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
                $accessToken->getScopes(),
            )),
            ...$this->workspaceOf($accessToken),
        ];
    }

    /**
     * CopyWorkspaceIdToAccessToken binds the workspace while the token is persisted, which
     * league finishes before it builds this response.
     *
     * @return array{workspace?: array{id: string, name: string}}
     */
    private function workspaceOf(AccessTokenEntityInterface $accessToken): array
    {
        $workspaceId = Passport::token()->newQuery()->whereKey($accessToken->getIdentifier())->value('workspace_id');

        if (! is_string($workspaceId)) {
            return [];
        }

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace instanceof Workspace) {
            return [];
        }

        return ['workspace' => ['id' => (string) $workspace->getKey(), 'name' => $workspace->name]];
    }
}
