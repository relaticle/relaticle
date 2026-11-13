<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Components\Infolists\RecordOverview;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Icon;
use Relaticle\EmailIntegration\Filament\Concerns\ProvidesComposerToAddress;

final class ViewCompany extends ViewRecord
{
    use HasRecordPageLayout;
    use ProvidesComposerToAddress;

    protected static string $resource = CompanyResource::class;

    public static function highlightNames(): array
    {
        return [
            'accountOwner.name',
            'custom_fields.domains',
            'custom_fields.icp',
            RecordOverview::NEXT_TASK,
            RecordOverview::LAST_INTERACTION,
            'custom_fields.linkedin',
        ];
    }

    public static function nativeDetailEntries(): array
    {
        return [
            RecordChipEntry::make('accountOwner.name')
                ->label(__('filament/resources/company.pages.view.infolist.fields.account_owner.label'))
                ->beforeLabel(Icon::make('lucide-circle-user')),
        ];
    }
}
