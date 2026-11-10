<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CrmEntity;
use App\Models\User;
use App\Queries\Filters\TreeFilter;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class ConditionsFilter
{
    public const string NAME = 'conditions';

    public static function make(): Filter
    {
        return Filter::make(self::NAME)
            ->form([
                Hidden::make('match')->default('all'),
                Hidden::make('rules')->default([]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $user = Filament::auth()->user();
                $entity = CrmEntity::tryFromModel($query->getModel());
                $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];

                if (! $user instanceof User || ! $entity instanceof CrmEntity || $rules === []) {
                    return $query;
                }

                return self::apply($query, $entity, $user, $rules);
            })
            ->indicateUsing(fn (): array => []);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<array-key, mixed>  $rules
     * @return Builder<Model>
     */
    private static function apply(Builder $query, CrmEntity $entity, User $user, array $rules): Builder
    {
        $zone = FilamentTimezone::get();
        $tree = (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for($entity, $user), $user, $zone);

        (new TreeFilter($entity, $user, $zone))($query, $tree, self::NAME);

        return $query;
    }
}
