<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;

final class EmailSettings extends Cluster
{
    use HasEmailFeatureFlag;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $slug = 'email-settings';

    /**
     * Reached from the workspace menu (alongside Custom Fields / Import History),
     * not the main sidebar — keep it out of the sidebar navigation tree.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('filament/clusters/email-settings.breadcrumb');
    }
}
