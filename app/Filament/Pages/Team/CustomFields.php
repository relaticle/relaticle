<?php

declare(strict_types=1);

namespace App\Filament\Pages\Team;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use Filament\Panel;
use Relaticle\CustomFields\Filament\Management\Pages\CustomFieldsManagementPage;

/**
 * The packaged custom fields screen, rendered as a workspace settings tab.
 *
 * Registered through `CustomFieldsPlugin::managementPage()` so it replaces the
 * packaged page rather than sitting beside it — there is one route to this
 * screen, not two.
 */
final class CustomFields extends CustomFieldsManagementPage
{
    use HasWorkspaceSettingsNavigation;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'team/custom-fields';
    }

    public function getSubheading(): ?string
    {
        return null;
    }
}
