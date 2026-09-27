<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken as PassportAccessToken;
use Relaticle\CustomFields\Services\TenantContextService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the workspace context for API/MCP requests using the token's workspace_id,
 * X-Workspace-Id header, or user's current workspace as fallback.
 *
 * WARNING: the User `tenant` scope and the web guard user are process-wide state that
 * terminate() resets. If terminate() were skipped under Octane, both would leak into the
 * next request. The CRM models' WorkspaceScope reads the request-scoped CurrentWorkspace.
 */
final readonly class SetApiWorkspaceContext
{
    private const string USER_SCOPE = 'tenant';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // The 'sanctum' guard is scoped to the users provider (config/auth.php), so
        // this should be unreachable in practice -- but a caller type mismatch here
        // must fail closed with a clean denial, not the uncaught TypeError a plain
        // `User $user` parameter on resolveWorkspace() below would throw for anything else.
        if (! $user instanceof User) {
            return response()->json(['message' => 'This credential cannot access workspace-scoped resources.'], 403);
        }

        $workspace = $this->resolveWorkspace($request, $user);

        if (! $workspace instanceof Workspace) {
            return response()->json(['message' => 'No workspace found.'], 403);
        }

        if (! $user->belongsToWorkspace($workspace)) {
            return response()->json(['message' => 'You do not belong to this workspace.'], 403);
        }

        // Set workspace in memory only. Do NOT call switchWorkspace(), which persists
        // current_workspace_id to the database, corrupting the web panel's workspace state
        // when API calls target a different workspace than the active web session.
        $user->forceFill(['current_workspace_id' => $workspace->getKey()]);
        $user->setRelation('currentWorkspace', $workspace);

        TenantContextService::setTenantId($workspace->getKey());

        // Override to web guard because Filament policies and observers check
        // auth('web')->user(). Without this, API requests through Sanctum would not be
        // recognized by the existing authorization layer.
        auth()->guard('web')->setUser($user);
        auth()->shouldUse('web');

        User::addGlobalScope(self::USER_SCOPE, fn (Builder $query): Builder => $query->memberOf($workspace));
        resolve(CurrentWorkspace::class)->set($workspace);

        return $next($request);
    }

    public function terminate(): void
    {
        auth()->guard('web')->forgetUser();
        TenantContextService::setTenantId(null);
        resolve(CurrentWorkspace::class)->forget();

        $scopes = Model::getAllGlobalScopes();
        unset($scopes[User::class][self::USER_SCOPE]);
        Model::setAllGlobalScopes($scopes);
    }

    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $token = $user->currentAccessToken();

        // Passport OAuth tokens are issued by our consent flow and ALWAYS carry a
        // workspace_id. The token's workspace_id is authoritative; X-Workspace-Id and currentWorkspace
        // are ignored. A Passport token without workspace_id is malformed (created outside
        // the MCP consent flow) and the request is rejected by returning null here.
        // @phpstan-ignore-next-line instanceof.alwaysFalse (User::currentAccessToken() is typed as Sanctum token but Passport replaces it at runtime via the api guard)
        if ($token instanceof PassportAccessToken) {
            if (! is_string($token->workspace_id) || $token->workspace_id === '') {
                return null;
            }

            return Workspace::query()->find($token->workspace_id);
        }

        // Sanctum personal access tokens (created from the Access Tokens UI in the
        // user's profile) can optionally pin a workspace at creation time. If unpinned,
        // X-Workspace-Id header or currentWorkspace applies, which is unchanged behavior.
        if ($token instanceof PersonalAccessToken && is_string($token->workspace_id)) {
            return Workspace::query()->find($token->workspace_id);
        }

        $workspaceId = $request->header('X-Workspace-Id');

        if (is_string($workspaceId) && Str::isUlid($workspaceId)) {
            return Workspace::query()->find($workspaceId);
        }

        return $user->currentWorkspace;
    }
}
