<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\CreationSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $created_by
 */
trait HasCreator
{
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
        return $this->creation_source === CreationSource::SYSTEM;
    }

    /**
     * @return Attribute<string, never>
     */
    protected function createdBy(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->creation_source === CreationSource::SYSTEM ?
                '⊙ System' :
                $this->creator->name ?? 'Unknown',
        );
    }
}
