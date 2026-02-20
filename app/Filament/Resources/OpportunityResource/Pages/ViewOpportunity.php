<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Pages;

use App\Filament\Actions\GenerateRecordSummaryAction;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Models\Opportunity;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Relaticle\CustomFields\Facades\CustomFields;

final class ViewOpportunity extends ViewRecord
{
    protected static string $resource = OpportunityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GenerateRecordSummaryAction::make(),
            EditAction::make()->icon('heroicon-o-pencil-square')->label('Edit'),
            ActionGroup::make([
                ActionGroup::make([
                    Action::make('copyPageUrl')
                        ->label('Copy page URL')
                        ->icon('heroicon-o-clipboard-document')
                        ->action(function (Opportunity $record): void {
                            $url = OpportunityResource::getUrl('view', [$record]);
                            $this->js("
                            navigator.clipboard.writeText('{$url}').then(() => {
                                new FilamentNotification()
                                    .title('URL copied to clipboard')
                                    .success()
                                    .send()
                            })
                        ");
                        }),
                    Action::make('copyRecordId')
                        ->label('Copy record ID')
                        ->icon('heroicon-o-clipboard-document')
                        ->action(function (Opportunity $record): void {
                            $id = $record->getKey();
                            $this->js("
                            navigator.clipboard.writeText('{$id}').then(() => {
                                new FilamentNotification()
                                    .title('Record ID copied to clipboard')
                                    .success()
                                    .send()
                            })
                        ");
                        }),
                ])->dropdown(false),
                DeleteAction::make(),
            ]),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Flex::make([
                    TextEntry::make('name')->grow(true),
                    TextEntry::make('company.name')
                        ->label('Company')
                        ->color('primary')
                        ->url(fn (Opportunity $record): ?string => $record->company ? CompanyResource::getUrl('view', [$record->company]) : null)
                        ->grow(false),
                    TextEntry::make('contact.name')
                        ->label('Point of Contact')
                        ->color('primary')
                        ->url(fn (Opportunity $record): ?string => $record->contact ? PeopleResource::getUrl('view', [$record->contact]) : null)
                        ->grow(false),
                ]),
                CustomFields::infolist()->forSchema($schema)->build()->columnSpanFull(),
            ])
                ->columnSpanFull(),
        ]);
    }
}
