<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\OwnerRole;
use Laravel\Jetstream\Role;

/**
 * Jetstream's HasTeams, ported so the columns can carry this application's
 * vocabulary. The vendor trait hardcodes `current_team_id` and `personal_team`,
 * which no amount of configuration can redirect.
 *
 * Filament hands tenants back as a bare `Model`, so the guards accept one and
 * narrow, exactly as the untyped vendor methods did.
 */
trait HasWorkspaces
{
    public function isCurrentWorkspace(?Model $workspace): bool
    {
        return $workspace instanceof Workspace
            && $workspace->getKey() === $this->currentWorkspace?->getKey();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        if ($this->current_workspace_id === null && $this->getKey() !== null) {
            $this->switchWorkspace($this->personalWorkspace());
        }

        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function switchWorkspace(?Model $workspace): bool
    {
        if (! $this->belongsToWorkspace($workspace)) {
            return false;
        }

        $this->forceFill(['current_workspace_id' => $workspace?->getKey()])->save();

        $this->setRelation('currentWorkspace', $workspace);

        return true;
    }

    /** @return Collection<int, Workspace> */
    public function allWorkspaces(): Collection
    {
        return $this->ownedWorkspaces->merge($this->workspaces)->sortBy('name');
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /**
     * @return BelongsToMany<Workspace, $this, Membership, 'membership'>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, Membership::class)
            ->withPivot('role')
            ->withTimestamps()
            ->as('membership');
    }

    public function personalWorkspace(): ?Workspace
    {
        return $this->ownedWorkspaces->where('personal_workspace', true)->first();
    }

    public function ownsWorkspace(?Model $workspace): bool
    {
        return $workspace instanceof Workspace
            && $this->getKey() === $workspace->getAttribute($this->getForeignKey());
    }

    public function belongsToWorkspace(?Model $workspace): bool
    {
        if (! $workspace instanceof Workspace) {
            return false;
        }

        return $this->ownsWorkspace($workspace)
            || $this->workspaces->contains(fn (Workspace $joined): bool => $joined->getKey() === $workspace->getKey());
    }

    public function workspaceRole(?Model $workspace): ?Role
    {
        if ($this->ownsWorkspace($workspace)) {
            return new OwnerRole;
        }

        if (! $workspace instanceof Workspace || ! $this->belongsToWorkspace($workspace)) {
            return null;
        }

        $membershipRole = $workspace->users
            ->first(fn (User $member): bool => $member->getKey() === $this->getKey())
            ?->membership
            ?->role;

        return $membershipRole === null ? null : Jetstream::findRole($membershipRole);
    }

    public function hasWorkspaceRole(?Model $workspace, string $role): bool
    {
        if ($this->ownsWorkspace($workspace)) {
            return true;
        }

        return $this->workspaceRole($workspace)?->key === $role;
    }
}
