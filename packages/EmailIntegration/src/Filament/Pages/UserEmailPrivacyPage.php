<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use Filament\Pages\Page;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailSettingsHeader;

final class UserEmailPrivacyPage extends Page
{
    use HasEmailFeatureFlag;
    use HasEmailSettingsHeader;
    use HasWorkspaceSettingsNavigation;

    protected string $view = 'email-integration::filament.pages.user-email-privacy';

    protected static ?string $slug = 'workspace/email/my-privacy';

    protected static ?string $title = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 5;

    /**
     * Blank so the stock full-width header is not rendered: the page view carries its
     * own `<x-email-integration::settings-header />` inside the content column.
     */
    protected ?string $heading = '';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user';

    public function getTitle(): string
    {
        return __('filament/pages/user-email-privacy.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament/pages/user-email-privacy.navigation_label');
    }
}
