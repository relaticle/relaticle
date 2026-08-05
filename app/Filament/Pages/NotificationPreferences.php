<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use Filament\Clusters\Cluster;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

final class NotificationPreferences extends Page
{
    protected string $view = 'filament.pages.notification-preferences';

    protected static ?string $slug = 'notifications';

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = Settings::class;

    public static function getNavigationLabel(): string
    {
        return __('notifications.title');
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
