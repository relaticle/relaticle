<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Filament\Clusters\EmailSettings;
use Relaticle\EmailIntegration\Filament\Concerns\HasClusterBreadcrumbs;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Livewire\Concerns\InteractsWithEmailAccessRequests;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRequestsPage extends Page implements HasTable
{
    use HasClusterBreadcrumbs;
    use HasEmailFeatureFlag;
    use InteractsWithEmailAccessRequests;
    use InteractsWithTable {
        InteractsWithEmailAccessRequests::table insteadof InteractsWithTable;
    }

    protected string $view = 'filament.pages.email-access-requests';

    protected static ?string $navigationLabel = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $cluster = EmailSettings::class;

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

        $count = EmailAccessRequest::query()
            ->where('owner_id', $user->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'primary';
    }
}
