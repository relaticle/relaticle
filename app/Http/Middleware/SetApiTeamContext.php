<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\PersonalAccessToken;
use App\Models\Scopes\TeamScope;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Relaticle\CustomFields\Services\TenantContextService;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetApiTeamContext
{
    /** @var list<class-string<\Illuminate\Database\Eloquent\Model>> */
    private const array SCOPED_MODELS = [
        User::class,
        Company::class,
        People::class,
        Opportunity::class,
        Task::class,
        Note::class,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        $team = $this->resolveTeam($request, $user);

        if (! $team instanceof Team) {
            return response()->json(['message' => 'No team found.'], 403);
        }

        if (! $user->belongsToTeam($team)) {
            return response()->json(['message' => 'You do not belong to this team.'], 403);
        }

        $user->switchTeam($team);

        TenantContextService::setTenantId($team->getKey());

        // Override to web guard because Filament policies, observers, and the TeamScope
        // global scope all check auth('web')->user(). Without this, API requests through
        // Sanctum would not be recognized by the existing authorization layer.
        auth()->guard('web')->setUser($user);
        auth()->shouldUse('web');

        $this->applyTenantScopes($team);

        return $next($request);
    }

    public function terminate(): void
    {
        TenantContextService::setTenantId(null);

        foreach (self::SCOPED_MODELS as $model) {
            $model::clearBootedModels();
        }
    }

    private function resolveTeam(Request $request, User $user): ?Team
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && is_string($token->team_id)) {
            return $token->team;
        }

        $teamId = $request->header('X-Team-Id');

        if (is_string($teamId) && preg_match('/^[0-9A-Za-z]{26}$/', $teamId)) {
            return Team::query()->find($teamId);
        }

        // @phpstan-ignore return.type (Jetstream's currentTeam is typed as Model|null)
        return $user->currentTeam;
    }

    private function applyTenantScopes(Team $team): void
    {
        $tenantId = $team->getKey();

        User::addGlobalScope(
            'tenant',
            fn (Builder $query) => $query
                ->whereHas('teams', fn (Builder $query) => $query->where('teams.id', $tenantId))
                ->orWhereHas('ownedTeams', fn (Builder $query) => $query->where('teams.id', $tenantId))
        );

        Company::addGlobalScope(new TeamScope);
        People::addGlobalScope(new TeamScope);
        Opportunity::addGlobalScope(new TeamScope);
        Task::addGlobalScope(new TeamScope);
        Note::addGlobalScope(new TeamScope);
    }
}
