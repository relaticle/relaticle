<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Livewire\App\Profile\LogoutOtherBrowserSessions;
use App\Livewire\App\Profile\ManageMfa;
use App\Livewire\App\Profile\ManagePasskeys;
use App\Livewire\App\Profile\UpdatePassword;
use Filament\Clusters\Cluster;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;

final class Security extends Page
{
    protected string $view = 'filament.pages.security';

    protected static ?string $slug = 'security';

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shield-line';

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = Settings::class;

    public static function getNavigationLabel(): string
    {
        return __('profile.security');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(UpdatePassword::class),
            Livewire::make(ManagePasskeys::class),
            Livewire::make(ManageMfa::class),
            Livewire::make(LogoutOtherBrowserSessions::class),
        ]);
    }

    public function getHeading(): string
    {
        return __('profile.security');
    }

    public static function getLabel(): string
    {
        return __('profile.security');
    }
}
