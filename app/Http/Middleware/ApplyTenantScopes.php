<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LogicException;

final readonly class ApplyTenantScopes
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Workspace, LogicException::class, 'Tenant scopes need a resolved workspace tenant.');

        $tenantId = $tenant->getKey();

        User::addGlobalScope(
            filament()->getTenancyScopeName(),
            fn (Builder $query) => $query
                ->whereHas('workspaces', fn (Builder $query) => $query->where('workspaces.id', $tenantId))
                ->orWhereHas('ownedWorkspaces', fn (Builder $query) => $query->where('workspaces.id', $tenantId))
        );

        resolve(CurrentWorkspace::class)->set($tenant);

        return $next($request);
    }
}
