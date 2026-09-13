<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
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
                ->whereHas('workspaces', fn (Builder $query) => $query->where('workspaces.id', $tenantId))
                ->orWhereHas('ownedWorkspaces', fn (Builder $query) => $query->where('workspaces.id', $tenantId))
        );

        Company::addGlobalScope(new WorkspaceScope);
        People::addGlobalScope(new WorkspaceScope);
        Opportunity::addGlobalScope(new WorkspaceScope);
        Task::addGlobalScope(new WorkspaceScope);
        Note::addGlobalScope(new WorkspaceScope);

        return $next($request);
    }
}
