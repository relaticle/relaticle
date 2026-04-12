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
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use Relaticle\CustomFields\Facades\CustomFields;

final class ViewOpportunity extends ViewRecord
{
    protected static string $resource = OpportunityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GenerateRecordSummaryAction::make(),
            Action::make('viewEmails')
                ->label('Emails')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->url(fn (): string => OpportunityResource::getUrl('emails', ['record' => $this->getRecord()])),
            EditAction::make()->icon('heroicon-o-pencil-square')->label('Edit'),
            ActionGroup::make([
                ActionGroup::make([
                    Action::make('copyPageUrl')
                        ->label('Copy page URL')
                        ->icon('heroicon-o-clipboard-document')
                        ->action(function (Opportunity $record): void {
                            $jsUrl = Js::from(OpportunityResource::getUrl('view', [$record]));
                            $this->js("
                            navigator.clipboard.writeText({$jsUrl}).then(() => {
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
                            $jsId = Js::from((string) $record->getKey());
                            $this->js("
                            navigator.clipboard.writeText({$jsId}).then(() => {
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
            ])->columnSpanFull(),

            Section::make('Communication Intelligence')
                ->icon(Heroicon::ChartBar)
                ->schema([
                    TextEntry::make('last_interaction_at')
                        ->label('Last Interaction')
                        ->dateTime()
                        ->placeholder('Never'),

                    TextEntry::make('last_email_at')
                        ->label('Last Email')
                        ->dateTime()
                        ->placeholder('Never'),

                    TextEntry::make('days_since_last_email')
                        ->label('Days Since Last Email')
                        ->getStateUsing(fn (Opportunity $record): string => $record->last_email_at
                            ? now()->diffInDays($record->last_email_at).' days ago'
                            : 'No emails yet'
                        ),

                    TextEntry::make('email_count')
                        ->label('Total Emails')
                        ->default(0),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(fn (Opportunity $record): bool => ($record->email_count ?? 0) === 0),
        ]);
    }
}
