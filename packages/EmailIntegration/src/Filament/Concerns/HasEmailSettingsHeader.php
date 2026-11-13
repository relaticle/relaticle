<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Email settings pages render their own header (heading, actions) from
 * `<x-email-integration::settings-header />` at the top of the page view, so it sits
 * with the page content under the workspace settings tabs. Each page blanks `$heading`
 * so the stock full-width header is not rendered.
 */
trait HasEmailSettingsHeader
{
    use HasEmailSettingsTrail;

    /**
     * Actions for the settings header. They cannot be registered as page header actions:
     * that alone makes Filament render the stock full-width header.
     *
     * @return array<int, Action>
     */
    public function settingsHeaderActions(): array
    {
        return [];
    }
}
