<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Support\ChatLocales;
use Illuminate\Auth\Events\Login;

/**
 * Seeds the chat locale once, from the browser, so a first-time user gets
 * their language without visiting settings. A stored value always wins.
 */
final class SeedUserLocaleListener
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        if ($user->locale !== null) {
            return;
        }

        $locale = $this->primaryLanguage((string) request()->header('Accept-Language', ''));

        if (! ChatLocales::isSupported($locale)) {
            return;
        }

        $user->forceFill(['locale' => $locale])->saveQuietly();
    }

    private function primaryLanguage(string $header): ?string
    {
        $first = trim(explode(',', $header, 2)[0]);
        $tag = trim(explode(';', $first, 2)[0]);
        $language = strtolower(explode('-', $tag, 2)[0]);

        return preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : null;
    }
}
