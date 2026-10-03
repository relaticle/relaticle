<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\CreationSource;
use App\Models\User;
use App\Support\CurrentSource;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $created_by
 */
trait HasCreator
{
    public function initializeHasCreator(): void
    {
        if ($this->exists) {
            return;
        }

        $this->attributes['creation_source'] ??= CurrentSource::get()->value;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Determine if the system created the record.
     */
    public function isSystemCreated(): bool
    {
        return in_array($this->creation_source, CreationSource::automated(), true);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ownData(Builder $query): void
    {
        $query->whereNotIn($query->qualifyColumn('creation_source'), CreationSource::automated());
    }

    /**
     * @return Attribute<string, never>
     */
    protected function createdBy(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->isSystemCreated() ?
                '⊙ System' :
                $this->creator?->name ?? 'Former Member', // @phpstan-ignore nullsafe.neverNull (creator_id can reference a deleted user)
        );
    }
}
