<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

final class NotificationPreferences extends Page
{
    protected string $view = 'filament.pages.notification-preferences';

    protected static ?string $slug = 'notifications';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getHeading(): string
    {
        return __('notifications.title');
    }

    public function getSubheading(): Htmlable
    {
        return new HtmlString(e(__('notifications.subtitle')));
    }

    public static function getLabel(): string
    {
        return __('notifications.title');
    }
}
