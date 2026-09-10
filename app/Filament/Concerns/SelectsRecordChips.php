<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

trait SelectsRecordChips
{
    abstract protected function recordChipTitleAttribute(): ?string;

    /**
     * A chip reads its avatar through the record, so an option list resolves one
     * media row per option unless the relationship query loads them together.
     *
     * @return Closure(Builder<Model>, ?string): Builder<Model>
     */
    protected function recordChipQuery(?Closure $modifyQueryUsing): Closure
    {
        return function (Builder $query, ?string $search) use ($modifyQueryUsing): Builder {
            if ($query->getModel() instanceof HasMedia) {
                $query->with('media');
            }

            if ($modifyQueryUsing instanceof Closure) {
                $query = $this->evaluate($modifyQueryUsing, [
                    'query' => $query,
                    'search' => $search,
                ]) ?? $query;
            }

            return $this->orderRecordChipOptions($query);
        };
    }

    /**
     * An option-label callback sends Filament down the branch of
     * Select::getOptionsFromRelationship() that never applies its default
     * alphabetical order, so the picker has to carry that order itself.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function orderRecordChipOptions(Builder $query): Builder
    {
        if (filled($query->getQuery()->orders)) {
            return $query;
        }

        $titleAttribute = $this->recordChipTitleAttribute();

        if (blank($titleAttribute)) {
            return $query;
        }

        return $query->orderBy(
            $query->qualifyColumn((string) str($titleAttribute)->before(' as ')),
        );
    }
}
