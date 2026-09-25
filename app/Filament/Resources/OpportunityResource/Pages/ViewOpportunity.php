<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Pages;

use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Concerns\EditsRecordFieldsInline;
use App\Filament\Concerns\RendersRecordSplitView;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Models\Opportunity;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

final class ViewOpportunity extends ViewRecord
{
    use EditsRecordFieldsInline;
    use RendersRecordSplitView;

    protected static string $resource = OpportunityResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $this->recordDetailsInfolist($schema, 'opportunityDetails', [
            $this->makeInlineEditable(
                TextEntry::make('name')
                    ->hiddenLabel()
                    ->inlineLabel(false),
                'name',
            ),
            $this->makeInlineEditable(
                RecordChipEntry::make('company.name')
                    ->label(__('filament/resources/opportunity.pages.view.infolist.fields.company.label'))
                    ->color('primary')
                    ->url(fn (Opportunity $record): ?string => $record->company ? CompanyResource::getUrl('view', [$record->company]) : null),
                'company_id',
            ),
            $this->makeInlineEditable(
                RecordChipEntry::make('contact.name')
                    ->label(__('filament/resources/opportunity.pages.view.infolist.fields.contact.label'))
                    ->color('primary')
                    ->url(fn (Opportunity $record): ?string => $record->contact ? PeopleResource::getUrl('view', [$record->contact]) : null),
                'contact_id',
            ),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function inlineEditEagerLoads(): array
    {
        return ['company.media', 'contact', 'customFieldValues.customField.options'];
    }

    protected function recordOverflowTranslationPrefix(): string
    {
        return 'filament/resources/opportunity';
    }
}
