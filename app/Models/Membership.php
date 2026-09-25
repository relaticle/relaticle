<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Membership as JetstreamMembership;

final class Membership extends JetstreamMembership
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'workspace_user';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    protected function getRoleNameAttribute(): string
    {
        // @phpstan-ignore-next-line nullCoalesce.expr
        return Jetstream::findRole($this->role)?->name ?? 'Unknown';
    }
}
