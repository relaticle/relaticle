<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Features\AccountDeletion;
use App\Filament\Clusters\Settings;
use App\Livewire\App\Profile\DeleteAccount;
use App\Livewire\App\Profile\UpdateProfileInformation;
use Filament\Clusters\Cluster;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Laravel\Pennant\Feature;

final class EditProfile extends Page
{
    protected string $view = 'filament.pages.edit-profile';

    protected static ?string $slug = 'profile';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = Settings::class;

    public static function getNavigationLabel(): string
    {
        return __('filament/panel.user_menu.profile');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(UpdateProfileInformation::class),
            Livewire::make(DeleteAccount::class)
                ->visible(fn (): bool => Feature::active(AccountDeletion::class)),
        ]);
    }

    public static function getLabel(): string
    {
        return __('profile.edit_profile');
    }
}
