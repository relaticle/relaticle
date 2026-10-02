<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeopleResource\Pages;

use App\Enums\CrmEntity;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\PeopleResource;
use App\Models\People;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Icon;
use Relaticle\EmailIntegration\Filament\Concerns\ProvidesComposerToAddress;

final class ViewPeople extends ViewRecord
{
    use HasRecordPageLayout;
    use ProvidesComposerToAddress;

    protected static string $resource = PeopleResource::class;

    protected function nativeDetailEntries(): array
    {
        return [
            RecordChipEntry::make('company.name')
                ->label(__('filament/resources/person.pages.view.infolist.fields.company.label'))
                ->beforeLabel(Icon::make(CrmEntity::Company->icon()))
                ->color('primary')
                ->url(fn (People $record): ?string => $record->company ? CompanyResource::getUrl('view', [$record->company]) : null),
        ];
    }
}
