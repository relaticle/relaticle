<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final readonly class RecentGenuineWorkspace implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder
            ->where($model->qualifyColumn('personal_workspace'), true)
            ->whereBetween($model->qualifyColumn('created_at'), [now()->subDays(30), now()->subDays(3)])
            ->whereIn($model->qualifyColumn('user_id'), User::query()
                ->withGlobalScope(GenuineSignup::class, new GenuineSignup)
                ->select('users.id'));
    }
}
