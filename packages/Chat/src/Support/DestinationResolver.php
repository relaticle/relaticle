<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Filament\Pages\EditTeam;
use App\Models\Team;
use Relaticle\CustomFields\Filament\Management\Pages\CustomFieldsManagementPage;
use Relaticle\ImportWizard\Filament\Pages\ImportCompanies;
use Relaticle\ImportWizard\Filament\Pages\ImportNotes;
use Relaticle\ImportWizard\Filament\Pages\ImportOpportunities;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;
use Relaticle\ImportWizard\Filament\Pages\ImportTasks;
use Throwable;

final readonly class DestinationResolver
{
    /** @var list<string> */
    public const array DESTINATIONS = [
        'custom_fields',
        'import_companies',
        'import_people',
        'import_opportunities',
        'import_tasks',
        'import_notes',
        'team_members',
    ];

    /**
     * Resolve a destination key to an absolute app-panel URL for the given team.
     *
     * Passes the panel and tenant explicitly so this works inside the queued chat
     * job, where no Filament panel/tenant is bound. Returns null when the
     * destination is unknown or the URL cannot be built.
     */
    public function resolve(string $destination, Team $team): ?string
    {
        try {
            return match ($destination) {
                'custom_fields' => CustomFieldsManagementPage::getUrl(panel: 'app', tenant: $team),
                'import_companies' => ImportCompanies::getUrl(panel: 'app', tenant: $team),
                'import_people' => ImportPeople::getUrl(panel: 'app', tenant: $team),
                'import_opportunities' => ImportOpportunities::getUrl(panel: 'app', tenant: $team),
                'import_tasks' => ImportTasks::getUrl(panel: 'app', tenant: $team),
                'import_notes' => ImportNotes::getUrl(panel: 'app', tenant: $team),
                'team_members' => EditTeam::getUrl(panel: 'app', tenant: $team),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
