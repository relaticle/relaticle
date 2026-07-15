<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Livewire\App\Profile\DeleteAccount;
use App\Livewire\App\Profile\LogoutOtherBrowserSessions;
use App\Livewire\App\Profile\UpdatePassword;
use App\Livewire\App\Profile\UpdateProfileInformation;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;

final class EditProfile extends Page
{
    protected string $view = 'filament.pages.edit-profile';

    protected static ?string $slug = 'profile';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(UpdateProfileInformation::class),
            Livewire::make(UpdatePassword::class),
            Livewire::make(LogoutOtherBrowserSessions::class),
            Livewire::make(DeleteAccount::class),
        ]);
    }

    public static function getLabel(): string
    {
        return __('profile.edit_profile');
    }
}
