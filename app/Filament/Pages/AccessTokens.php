<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Livewire\App\AccessTokens\CreateAccessToken;
use App\Livewire\App\AccessTokens\ManageAccessTokens;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Override;

final class AccessTokens extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected string $view = 'filament.pages.access-tokens';

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(CreateAccessToken::class),
            Livewire::make(ManageAccessTokens::class),
        ]);
    }

    public static function getLabel(): string
    {
        return __('access-tokens.title');
    }
}
