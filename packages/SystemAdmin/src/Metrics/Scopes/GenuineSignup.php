<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class GenuineSignup implements Scope
{
    /**
     * @param  Builder<User>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $id = $model->qualifyColumn('id');
        $createdAt = $model->qualifyColumn('created_at');

        $builder
            ->whereNotNull($model->qualifyColumn('email_verified_at'))
            ->whereNotExists(fn (QueryBuilder $invited): QueryBuilder => $invited
                ->selectRaw('1')
                ->from('workspace_user')
                ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
                ->whereColumn('workspace_user.user_id', $id)
                ->whereColumn('workspaces.user_id', '!=', $id)
                ->whereRaw("workspace_user.created_at <= {$createdAt} + interval '24 hours'"))
            ->whereNotIn($id, InternalWorkspace::ownerIds())
            ->whereNotIn($id, Workspace::query()
                ->withGlobalScope(TrialFarmer::class, new TrialFarmer)
                ->whereNotNull('user_id')
                ->select('user_id'));
    }
}
