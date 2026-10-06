<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

trait RemembersViewMode
{
    public static function rememberViewMode(string $pageName): void
    {
        session()->put(static::viewModeSessionKey(), $pageName);
    }

    public static function getNavigationUrl(): string
    {
        $pageName = session()->get(static::viewModeSessionKey());

        if (! is_string($pageName) || ! static::hasPage($pageName)) {
            return static::getUrl();
        }

        if (! static::getPages()[$pageName]->getPage()::canAccess()) {
            return static::getUrl();
        }

        return static::getUrl($pageName);
    }

    private static function viewModeSessionKey(): string
    {
        return 'view-mode.'.static::class;
    }
}
