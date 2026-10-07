<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Enums\EmailGrant;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\HostedWorkspaceAccess;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as BaseApproveAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP-aware approve handler. Validates that the user has selected exactly one
 * workspace they belong to, stashes the workspace_id in the session so the AuthCode
 * model's creating hook can persist it, then completes the request. Email scopes come
 * from the user's role in that workspace, never from what the client asked for, and a
 * record scope the role lacks is dropped.
 */
final class ApproveAuthorizationController extends BaseApproveAuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        private readonly HostedWorkspaceAccess $access,
    ) {
        parent::__construct($server);
    }

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'string', 'size:26'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $workspace = Workspace::query()->find($validated['workspace_id']);

        abort_if(! $workspace instanceof Workspace, 422, 'Selected workspace does not exist.');
        abort_if(! $user->belongsToWorkspace($workspace), 403, 'You do not belong to the selected workspace.');

        // A paused workspace answers 402 on every MCP call, so approving here would mint
        // a token that can never do anything. Refuse at consent rather than hand the user
        // a connector that silently fails on first use.
        abort_if($this->access->isPaused($workspace), 402, 'This workspace is paused. Subscribe to Cloud Pro before connecting it to an AI assistant.');

        $request->session()->put('mcp.oauth.workspace_id', $workspace->getKey());

        try {
            $authRequest = $this->getAuthRequestFromSession($request);
            $authRequest->setScopes($this->scopesFor($user, $workspace, $authRequest->getScopes()));
            $authRequest->setAuthorizationApproved(true);

            return $this->withErrorHandling(fn (): Response => $this->convertResponse(
                $this->server->completeAuthorizationRequest($authRequest, $psrResponse),
            ), $authRequest->getGrantTypeId() === 'implicit');
        } finally {
            $request->session()->forget('mcp.oauth.workspace_id');
        }
    }

    /**
     * @param  array<int, ScopeEntityInterface>  $requested
     * @return list<ScopeEntityInterface>
     */
    private function scopesFor(User $user, Workspace $workspace, array $requested): array
    {
        $grantable = $user->grantableTokenPermissions($workspace->getKey());
        $withheld = array_diff(WorkspaceCapability::tokenPermissions(WorkspaceCapability::forOwner()), $grantable);

        $requested = array_values(array_filter(
            $requested,
            fn (ScopeEntityInterface $scope): bool => EmailGrant::tryFrom($scope->getIdentifier()) === null
                && ! in_array($scope->getIdentifier(), $withheld, true),
        ));

        $identifiers = array_map(fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $requested);

        if (! in_array(Registrar::OAUTH_SCOPE, $identifiers, true)) {
            return $requested;
        }

        return [
            ...$requested,
            ...array_map(
                fn (EmailGrant $grant): Scope => new Scope($grant->value),
                EmailGrant::fromValues($grantable),
            ),
        ];
    }
}
