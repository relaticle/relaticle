<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\HostedWorkspaceAccess;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as BaseApproveAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP-aware approve handler. Validates that the user has selected exactly one
 * workspace they belong to, stashes the workspace_id in the session so the AuthCode
 * model's creating hook can persist it, then delegates to Passport's standard
 * approve flow.
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

        return parent::approve($request, $psrResponse);
    }
}
