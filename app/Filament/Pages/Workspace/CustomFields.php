<?php

declare(strict_types=1);

namespace App\Filament\Pages\Workspace;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Relaticle\CustomFields\Filament\Management\Pages\CustomFieldsManagementPage;

/**
 * The packaged custom fields screen, rendered as a workspace settings tab.
 *
 * Registered through `CustomFieldsPlugin::managementPage()` so it replaces the
 * packaged page rather than sitting beside it. There is one route to this
 * screen, not two.
 */
final class CustomFields extends CustomFieldsManagementPage
{
    use HasWorkspaceSettingsNavigation;

    public const Heroicon NAVIGATION_ICON = Heroicon::OutlinedCube;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'workspace/custom-fields';
    }

    public function getSubheading(): ?string
    {
        return null;
    }
}
