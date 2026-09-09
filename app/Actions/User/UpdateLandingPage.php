<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\LandingPage;
use App\Models\User;

final readonly class UpdateLandingPage
{
    public function execute(User $user, LandingPage $landingPage): void
    {
        $user->update(['landing_page' => $landingPage]);
    }
}
