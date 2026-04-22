<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Livewire\App\Email\UserEmailPrivacySettings;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;

final class EmailPrivacySettingsPage extends Page
{
    protected string $view = 'filament.pages.email-privacy-settings';

    protected static ?string $slug = 'email-privacy';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(UserEmailPrivacySettings::class),
        ]);
    }

    public static function getLabel(): string
    {
        return 'Email privacy';
    }
}
