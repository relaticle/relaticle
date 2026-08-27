<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Livewire\App\AccessTokens\CreateAccessToken;
use App\Livewire\App\AccessTokens\ManageAccessTokens;
use App\Livewire\App\AccessTokens\ManageOAuthConnectors;
use Filament\Clusters\Cluster;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Laravel\Jetstream\Features;
use Override;

final class AccessTokens extends Page
{
    protected string $view = 'filament.pages.access-tokens';

    protected static ?string $slug = 'access-tokens';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = Settings::class;

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return Features::hasApiFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('access-tokens.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(CreateAccessToken::class),
            Livewire::make(ManageAccessTokens::class),
            Livewire::make(ManageOAuthConnectors::class),
        ]);
    }

    public static function getLabel(): string
    {
        return __('access-tokens.title');
    }
}
