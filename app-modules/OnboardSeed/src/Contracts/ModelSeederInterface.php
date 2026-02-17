<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\Contracts;

use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

interface ModelSeederInterface
{
    public function seed(Team $team, Authenticatable $user): void;

    /**
     * Get custom fields for this model
     *
     * @return Collection<string, mixed>
     */
    public function customFields(): Collection;

    /**
     * Initialize the seeder with necessary dependencies
     *
     * @return $this
     */
    public function initialize(): self;
}
