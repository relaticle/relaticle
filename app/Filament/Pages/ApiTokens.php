<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Livewire\App\ApiTokens\CreateApiToken;
use App\Livewire\App\ApiTokens\ManageApiTokens;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Override;

final class ApiTokens extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected string $view = 'filament.pages.api-tokens';

    protected static ?string $navigationLabel = null;

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationSort(): int
    {
        return 1;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(CreateApiToken::class),
            Livewire::make(ManageApiTokens::class),
        ]);
    }

    public static function getLabel(): string
    {
        return __('access-tokens.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('access-tokens.title');
    }
}
