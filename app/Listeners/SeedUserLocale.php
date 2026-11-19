<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Support\Locales;
use Illuminate\Auth\Events\Login;

final readonly class SeedUserLocale
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->locale !== null) {
            return;
        }

        $locale = Locales::fromHeader(request()->header('Accept-Language'));

        if ($locale === null) {
            return;
        }

        $user->forceFill(['locale' => $locale])->saveQuietly();
    }
}
