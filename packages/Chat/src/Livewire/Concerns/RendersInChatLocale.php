<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Livewire calls boot<Trait> on every request and dehydrate<Trait> after
 * the response is built, which brackets both actions and the render.
 */
trait RendersInChatLocale
{
    private ?string $appLocaleBeforeChat = null;

    private ?string $dateLocaleBeforeChat = null;

    public function bootRendersInChatLocale(): void
    {
        $user = auth('web')->user();

        if (! $user instanceof User) {
            return;
        }

        $this->appLocaleBeforeChat = app()->getLocale();
        $this->dateLocaleBeforeChat = Date::getLocale();

        app()->setLocale($user->chatLocale());
        Date::setLocale($user->chatLocale());
    }

    public function dehydrateRendersInChatLocale(): void
    {
        if ($this->appLocaleBeforeChat === null) {
            return;
        }

        app()->setLocale($this->appLocaleBeforeChat);
        Date::setLocale($this->dateLocaleBeforeChat ?? $this->appLocaleBeforeChat);

        $this->appLocaleBeforeChat = null;
        $this->dateLocaleBeforeChat = null;
    }
}
