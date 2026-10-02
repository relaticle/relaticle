<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Pages;

use App\Enums\CrmEntity;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Models\Opportunity;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Icon;
use Relaticle\EmailIntegration\Filament\Concerns\ProvidesComposerToAddress;

final class ViewOpportunity extends ViewRecord
{
    use HasRecordPageLayout;
    use ProvidesComposerToAddress;

    protected static string $resource = OpportunityResource::class;

    protected function nativeDetailEntries(): array
    {
        return [
            RecordChipEntry::make('company.name')
                ->label(__('filament/resources/opportunity.pages.view.infolist.fields.company.label'))
                ->beforeLabel(Icon::make(CrmEntity::Company->icon()))
                ->color('primary')
                ->url(fn (Opportunity $record): ?string => $record->company ? CompanyResource::getUrl('view', [$record->company]) : null),
            RecordChipEntry::make('contact.name')
                ->label(__('filament/resources/opportunity.pages.view.infolist.fields.contact.label'))
                ->beforeLabel(Icon::make(CrmEntity::People->icon()))
                ->color('primary')
                ->url(fn (Opportunity $record): ?string => $record->contact ? PeopleResource::getUrl('view', [$record->contact]) : null),
        ];
    }
}
