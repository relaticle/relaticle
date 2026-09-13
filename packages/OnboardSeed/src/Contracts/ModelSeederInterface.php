<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\Contracts;

use App\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

interface ModelSeederInterface
{
    public function seed(Workspace $workspace, Authenticatable $user): void;

    /**
     * Get custom fields for this model
     *
     * @return Collection<string, mixed>
     */
    public function customFields(): Collection;

    /**
     * Initialize the seeder with necessary dependencies
     */
    public function initialize(): self;
}
