<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Icon;
use Filament\Support\Icons\Heroicon;
use Relaticle\EmailIntegration\Filament\Concerns\ProvidesComposerToAddress;

final class ViewCompany extends ViewRecord
{
    use HasRecordPageLayout;
    use ProvidesComposerToAddress;

    protected static string $resource = CompanyResource::class;

    protected function nativeDetailEntries(): array
    {
        return [
            RecordChipEntry::make('accountOwner.name')
                ->label(__('filament/resources/company.pages.view.infolist.fields.account_owner.label'))
                ->beforeLabel(Icon::make(Heroicon::OutlinedUserCircle)),
        ];
    }
}
