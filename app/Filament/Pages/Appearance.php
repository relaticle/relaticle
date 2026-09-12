<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AccentColor;
use App\Filament\Clusters\Settings;
use App\Support\BrandColors;
use Filament\Clusters\Cluster;
use Filament\Enums\ThemeMode;
use Filament\Pages\Page;

final class Appearance extends Page
{
    protected string $view = 'filament.pages.appearance';

    protected static string $layout = 'filament.layouts.settings';

    protected static ?string $slug = 'appearance';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = Settings::class;

    public static function getNavigationLabel(): string
    {
        return __('appearance.title');
    }

    public function getHeading(): string
    {
        return __('appearance.title');
    }

    public static function getLabel(): string
    {
        return __('appearance.title');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'accents' => AccentColor::cases(),
            'brandColor' => BrandColors::primary()['DEFAULT'],
            'themes' => ThemeMode::cases(),
        ];
    }
}
