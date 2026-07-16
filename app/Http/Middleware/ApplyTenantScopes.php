<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\TeamScope;
use App\Models\Task;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final readonly class ApplyTenantScopes
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenantId = Filament::getTenant()->getKey();
        User::addGlobalScope(
            filament()->getTenancyScopeName(),
            fn (Builder $query) => $query
                ->whereHas('teams', fn (Builder $query) => $query->where('teams.id', $tenantId))
                ->orWhereHas('ownedTeams', fn (Builder $query) => $query->where('teams.id', $tenantId))
        );

        Company::addGlobalScope(new TeamScope);
        People::addGlobalScope(new TeamScope);
        Opportunity::addGlobalScope(new TeamScope);
        Task::addGlobalScope(new TeamScope);
        Note::addGlobalScope(new TeamScope);

        return $next($request);
    }
}
