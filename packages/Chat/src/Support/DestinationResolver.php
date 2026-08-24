<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Filament\Pages\Team\CustomFields;
use App\Filament\Pages\Team\Members;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Team;
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
        'export_companies',
        'export_people',
        'export_opportunities',
        'export_tasks',
        'export_notes',
        'team_members',
    ];

    /**
     * Query parameters that open the record list's export modal on page load.
     *
     * Filament binds `?action=` to `InteractsWithActions::$defaultAction`, so this
     * lands the user in the export dialog itself rather than on the list page with
     * the export button still hidden inside the "Import / Export" dropdown.
     *
     * @var array<string, string>
     */
    private const array EXPORT_ACTION = ['action' => 'export'];

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
                'custom_fields' => CustomFields::getUrl(panel: 'app', tenant: $team),
                'import_companies' => ImportCompanies::getUrl(panel: 'app', tenant: $team),
                'import_people' => ImportPeople::getUrl(panel: 'app', tenant: $team),
                'import_opportunities' => ImportOpportunities::getUrl(panel: 'app', tenant: $team),
                'import_tasks' => ImportTasks::getUrl(panel: 'app', tenant: $team),
                'import_notes' => ImportNotes::getUrl(panel: 'app', tenant: $team),
                'export_companies' => ListCompanies::getUrl(self::EXPORT_ACTION, panel: 'app', tenant: $team),
                'export_people' => ListPeople::getUrl(self::EXPORT_ACTION, panel: 'app', tenant: $team),
                'export_opportunities' => ListOpportunities::getUrl(self::EXPORT_ACTION, panel: 'app', tenant: $team),
                'export_tasks' => ManageTasks::getUrl(self::EXPORT_ACTION, panel: 'app', tenant: $team),
                'export_notes' => ManageNotes::getUrl(self::EXPORT_ACTION, panel: 'app', tenant: $team),
                'team_members' => Members::getUrl(panel: 'app', tenant: $team),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
