<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailSettingsHeader;
use Relaticle\EmailIntegration\Livewire\Concerns\InteractsWithEmailAccessRequests;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRequestsPage extends Page implements HasTable
{
    use HasEmailFeatureFlag;
    use HasEmailSettingsHeader;
    use HasWorkspaceSettingsNavigation;
    use InteractsWithEmailAccessRequests;
    use InteractsWithTable {
        InteractsWithEmailAccessRequests::table insteadof InteractsWithTable;
    }

    protected string $view = 'filament.pages.email-access-requests';

    protected static ?string $slug = 'workspace/email/access-requests';

    protected static ?string $navigationLabel = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 6;

    protected ?string $heading = '';

    public static function getNavigationLabel(): string
    {
        return __('filament/pages/email-access-requests.navigation_label');
    }

    public function getTitle(): string
    {
        return __('filament/pages/email-access-requests.navigation_label');
    }

    public static function getNavigationBadge(): ?string
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $count = EmailAccessRequest::query()->pendingIncomingFor($user)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'primary';
    }
}
